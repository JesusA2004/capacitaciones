<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AnalizarContactosEmergenciaRequest;
use App\Http\Requests\Rh\AplicarContactosEmergenciaRequest;
use App\Http\Requests\Rh\AsignarColaboradorContactosEmergenciaRequest;
use App\Models\ImportacionExcel;
use App\Services\ImportadorContactosEmergencia\ImportadorContactosEmergenciaService;
use App\Support\Paginacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Importador del Excel de contactos de emergencia del colaborador: rellena
 * en bloque teléfono personal, contacto de emergencia, condición médica y
 * alergias sobre colaboradores ya existentes. Dos pasos: vista previa
 * (analizar, no guarda) → confirmar (aplicar). Toda la regla vive en
 * ImportadorContactosEmergenciaService. Es SOLO importación (la consulta de
 * esos datos vive en el expediente): permiso `contactos_emergencia.importar`,
 * reservado a super_admin (2026-10-20).
 */
class ContactosEmergenciaController extends Controller
{
    public function __construct(private readonly ImportadorContactosEmergenciaService $importador) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()->can('contactos_emergencia.importar'), 403);

        return Inertia::render('Rh/ContactosEmergencia/Index', [
            'columnas' => array_values(ImportadorContactosEmergenciaService::COLUMNAS),
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

    /** Plantilla vacía con los encabezados oficiales. */
    public function plantilla(Request $request): HttpResponse
    {
        abort_unless($request->user()->can('contactos_emergencia.importar'), 403);

        return response($this->importador->plantilla(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Plantilla contactos de emergencia.xlsx"',
            'Cache-Control' => 'no-store',
        ]);
    }

    /** Paso 1: vista previa. No escribe nada en los colaboradores. */
    public function analizar(AnalizarContactosEmergenciaRequest $request): RedirectResponse
    {
        $importacion = $this->importador->analizar($request->file('archivo'), $request->user());

        return redirect()->route('rh.contactos-emergencia.show', $importacion)
            ->with('toast', ['type' => 'success', 'message' => sprintf('Vista previa lista: %d fila(s). Nada se ha guardado todavía; revisa y confirma.', $importacion->total_filas)]);
    }

    public function show(Request $request, ImportacionExcel $importacion): Response
    {
        abort_unless($request->user()->can('contactos_emergencia.importar'), 403);
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);
        $importacion->loadMissing(['subidoPor:id,name,apellidos', 'aplicadoPor:id,name,apellidos']);

        return Inertia::render('Rh/ContactosEmergencia/Show', [
            'importacion' => [
                'id' => $importacion->id,
                'nombre_archivo' => $importacion->nombre_archivo,
                'estado' => $importacion->estado,
                'total_filas' => $importacion->total_filas,
                'filas_aplicadas' => $importacion->filas_aplicadas,
                'detalle' => $this->importador->detalleParaMostrar($importacion, $request->user()),
                'columnas' => $importacion->metadatos['columnas'] ?? [],
                'columnas_ignoradas' => $importacion->metadatos['columnas_ignoradas'] ?? [],
                'resumen' => $importacion->metadatos['resumen'] ?? null,
                'reanalisis_de' => $importacion->metadatos['reanalisis_de'] ?? null,
                'subido_por' => $importacion->subidoPor->name,
                'creado_en' => $importacion->created_at?->toDateTimeString(),
                'aplicado_por' => $importacion->aplicadoPor?->name,
                'aplicado_en' => $importacion->aplicado_en?->toDateTimeString(),
            ],
        ]);
    }

    /** Buscador del selector manual (sin mínimo de letras). */
    public function colaboradores(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('contactos_emergencia.importar'), 403);
        $datos = $request->validate(['q' => ['nullable', 'string', 'max:80']]);

        return response()->json(['data' => $this->importador->buscarColaboradores($request->user(), (string) ($datos['q'] ?? ''))]);
    }

    /** Fila ambigua o no encontrada: RH elige al colaborador a mano. */
    public function asignar(AsignarColaboradorContactosEmergenciaRequest $request, ImportacionExcel $importacion, int $fila): RedirectResponse
    {
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);
        $this->importador->asignarColaborador($importacion, $fila, (int) $request->validated('colaborador_id'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('Colaborador asignado a la fila %d.', $fila)]);
    }

    /** Paso 2: confirmar y aplicar solo las filas válidas/elegidas. */
    public function aplicar(AplicarContactosEmergenciaRequest $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);

        /** @var list<int>|null $filas */
        $filas = $request->has('filas') ? array_values(array_map('intval', (array) $request->validated('filas', []))) : null;
        $confirmadas = array_values(array_map('intval', (array) $request->validated('confirmadas', [])));

        $resumen = $this->importador->aplicar($importacion, $request->user(), $filas, $confirmadas);

        return back()->with('toast', [
            'type' => $resumen['errores'] === [] ? 'success' : 'warning',
            'message' => sprintf('Importación aplicada: %d actualizado(s), %d sin cambios, %d omitido(s).', $resumen['actualizados'], $resumen['sin_cambios'], $resumen['omitidos']),
        ]);
    }

    public function destroy(Request $request, ImportacionExcel $importacion): RedirectResponse
    {
        abort_unless($request->user()->can('contactos_emergencia.importar'), 403);
        abort_unless($importacion->tipo === ImportadorContactosEmergenciaService::TIPO, 404);
        $this->importador->eliminar($importacion, $request->user());

        return to_route('rh.contactos-emergencia.index')->with('toast', ['type' => 'success', 'message' => sprintf('Se quitó «%s».', $importacion->nombre_archivo)]);
    }
}
