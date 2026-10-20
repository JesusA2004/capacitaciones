<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AnalizarContactosEmergenciaRequest;
use App\Http\Requests\Rh\AplicarContactosEmergenciaRequest;
use App\Models\ImportacionExcel;
use App\Services\ImportadorContactosEmergencia\ImportadorContactosEmergenciaService;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importador del Excel de contactos de emergencia del colaborador: rellena
 * en bloque teléfono personal, contacto de emergencia, condición médica y
 * alergias sobre colaboradores ya existentes. Flujo subir → analizar
 * (preview) → revisar → aplicar. Mismo Service que usaría la API si hiciera
 * falta (RH lo opera desde la web).
 */
class ContactosEmergenciaController extends Controller
{
    public function __construct(private readonly ImportadorContactosEmergenciaService $importador) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('expedientes.editar'), 403);

        return Inertia::render('Rh/ContactosEmergencia/Index', [
            'importaciones' => ImportacionExcel::query()
                ->where('tipo', ImportadorContactosEmergenciaService::TIPO)
                ->with(['subidoPor:id,name,apellidos', 'aplicadoPor:id,name,apellidos'])
                ->orderByDesc('id')
                ->paginate(Paginacion::porPagina())
                ->through(fn (ImportacionExcel $i): array => [
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

    public function analizar(AnalizarContactosEmergenciaRequest $request): RedirectResponse
    {
        $importacion = $this->importador->analizar($request->file('archivo'), $request->user());

        return redirect()->route('rh.contactos-emergencia.show', $importacion)
            ->with('toast', ['type' => 'success', 'message' => "Archivo analizado: {$importacion->total_filas} fila(s). Revisa el detalle antes de aplicar."]);
    }

    public function show(Request $request, ImportacionExcel $importacion): Response
    {
        abort_unless($request->user()->can('expedientes.editar'), 403);
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);

        return Inertia::render('Rh/ContactosEmergencia/Show', [
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

    public function aplicar(AplicarContactosEmergenciaRequest $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);
        $resultado = $this->importador->aplicar($importacion, $request->user(), $request->validated('filas'));

        return back()->with('toast', [
            'type' => $resultado['errores'] === [] ? 'success' : 'warning',
            'message' => "Contactos aplicados: {$resultado['filas_aplicadas']} colaborador(es) actualizado(s).".($resultado['errores'] !== [] ? ' Hubo errores, revisa el detalle.' : ''),
        ]);
    }

    public function destroy(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('expedientes.editar'), 403);
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);
        $this->importador->eliminar($importacion, $request->user());

        return to_route('rh.contactos-emergencia.index')->with('toast', ['type' => 'success', 'message' => sprintf('Se quitó «%s».', $importacion->nombre_archivo)]);
    }
}
