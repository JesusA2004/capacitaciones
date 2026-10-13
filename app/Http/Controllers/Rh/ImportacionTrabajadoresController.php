<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AnalizarImportacionTrabajadoresRequest;
use App\Http\Requests\Rh\AplicarImportacionTrabajadoresRequest;
use App\Models\ImportacionExcel;
use App\Services\ImportadorTrabajadores\ImportadorTrabajadoresService;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importador de docs/onboarding/REPORTE_TRABAJADORES_VAC.xlsx: salarios y
 * vacaciones históricas. Flujo obligatorio subir → analizar (preview) →
 * revisar → aplicar — nunca se escribe en colaboradores sin ese paso de
 * revisión explícito. Mismo Service que exigiría la API móvil si llegara a
 * necesitarse ahí (RH opera esto solo desde la web).
 */
class ImportacionTrabajadoresController extends Controller
{
    public function __construct(private readonly ImportadorTrabajadoresService $importador) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('colaboradores.importar_salarios_vacaciones'), 403);

        return Inertia::render('Rh/Importaciones/Index', [
            'importaciones' => ImportacionExcel::query()
                ->with(['subidoPor:id,name,apellidos', 'aplicadoPor:id,name,apellidos'])
                ->orderByDesc('id')
                ->paginate(Paginacion::porPagina())
                ->through(fn (ImportacionExcel $i) => [
                    'id' => $i->id,
                    'nombre_archivo' => $i->nombre_archivo,
                    'estado' => $i->estado,
                    'total_filas' => $i->total_filas,
                    'filas_aplicadas' => $i->filas_aplicadas,
                    'filas_revision' => $i->filas_revision,
                    'filas_sin_cambios' => $i->filas_sin_cambios,
                    'filas_no_encontradas' => $i->filas_no_encontradas,
                    'filas_error' => $i->filas_error,
                    'subido_por' => $i->subidoPor->name,
                    'aplicado_por' => $i->aplicadoPor?->name,
                    'aplicado_en' => $i->aplicado_en?->toDateTimeString(),
                    'creado_en' => $i->created_at?->toDateTimeString(),
                ]),
        ]);
    }

    public function analizar(AnalizarImportacionTrabajadoresRequest $request): RedirectResponse
    {
        $importacion = $this->importador->analizar($request->file('archivo'), $request->user());

        return redirect()->route('rh.importaciones.show', $importacion)
            ->with('toast', ['type' => 'success', 'message' => "Archivo analizado: {$importacion->total_filas} filas. Revisa el detalle antes de aplicar."]);
    }

    public function show(Request $request, ImportacionExcel $importacion): Response
    {
        abort_unless($request->user()->can('colaboradores.importar_salarios_vacaciones'), 403);

        return Inertia::render('Rh/Importaciones/Show', [
            'importacion' => [
                'id' => $importacion->id,
                'nombre_archivo' => $importacion->nombre_archivo,
                'estado' => $importacion->estado,
                'total_filas' => $importacion->total_filas,
                'filas_aplicadas' => $importacion->filas_aplicadas,
                'filas_revision' => $importacion->filas_revision,
                'filas_sin_cambios' => $importacion->filas_sin_cambios,
                'filas_no_encontradas' => $importacion->filas_no_encontradas,
                'filas_error' => $importacion->filas_error,
                'detalle' => $importacion->detalle ?? [],
                'aplicado_en' => $importacion->aplicado_en?->toDateTimeString(),
            ],
        ]);
    }

    /** Quita un archivo analizado que ya no se aplicará (las aplicadas no se quitan). */
    public function destroy(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('colaboradores.importar_salarios_vacaciones'), 403);
        $this->importador->eliminar($importacion, $request->user());

        return to_route('rh.importaciones.index')->with('toast', ['type' => 'success', 'message' => sprintf('Se quitó «%s».', $importacion->nombre_archivo)]);
    }

    public function aplicar(AplicarImportacionTrabajadoresRequest $request, ImportacionExcel $importacion): RedirectResponse
    {
        $resultado = $this->importador->aplicar($importacion, $request->user(), $request->validated('filas'));

        return back()->with('toast', [
            'type' => $resultado['errores'] === [] ? 'success' : 'warning',
            'message' => "Importación aplicada: {$resultado['filas_aplicadas']} filas actualizadas.".($resultado['errores'] !== [] ? ' Hubo errores, revisa el detalle.' : ''),
        ]);
    }
}
