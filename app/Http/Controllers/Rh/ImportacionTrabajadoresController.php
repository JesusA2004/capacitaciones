<?php

namespace App\Http\Controllers\Rh;

use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\AnalizarImportacionTrabajadoresRequest;
use App\Http\Requests\Rh\AplicarImportacionTrabajadoresRequest;
use App\Http\Requests\Rh\AsignarColaboradorImportacionRequest;
use App\Http\Requests\Rh\CrearColaboradorImportacionRequest;
use App\Models\Departamento;
use App\Models\ImportacionExcel;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
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

        $detalle = $importacion->detalle ?? [];
        $idsActores = collect($detalle)->pluck('match_actor_id')->merge(collect($detalle)->pluck('confirmado_por'))->filter()->unique()->all();
        $nombresActores = User::query()->whereIn('id', $idsActores)->get(['id', 'name', 'apellidos'])
            ->mapWithKeys(fn (User $u) => [$u->id => trim("{$u->name} {$u->apellidos}")]);

        $detalle = array_map(function (array $fila) use ($nombresActores, $importacion): array {
            $fila['match_actor_nombre'] = isset($fila['match_actor_id']) ? ($nombresActores[$fila['match_actor_id']] ?? null) : null;
            $fila['confirmado_por_nombre'] = isset($fila['confirmado_por']) ? ($nombresActores[$fila['confirmado_por']] ?? null) : null;

            // Análisis de corrección segura (CLAUDE.md §70-71): solo donde
            // aplica (match no exacto + ya aplicado + cambió el salario) —
            // nunca revierte nada, solo informa si es seguro corregir.
            if (($fila['aplicado'] ?? false) && ($fila['cambia_salario'] ?? false) && ($fila['match_tipo'] ?? 'exacto') !== 'exacto') {
                $fila['analisis_correccion'] = $this->importador->analizarCorreccion($importacion, (int) $fila['fila']);
            } else {
                $fila['analisis_correccion'] = null;
            }

            return $fila;
        }, $detalle);

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
                'detalle' => $detalle,
                'aplicado_en' => $importacion->aplicado_en?->toDateTimeString(),
            ],
            // Solo para "Crear colaborador" en filas sin match (CLAUDE.md
            // §74-81): estructura mínima que el Excel no trae.
            'opciones' => [
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
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

    /**
     * RH elige a mano el colaborador de una fila sin match exacto (nombre no
     * exacto o ambiguo) — nunca un auto-merge por similitud (CLAUDE.md §63-65).
     */
    public function asignarColaborador(AsignarColaboradorImportacionRequest $request, ImportacionExcel $importacion, int $fila): RedirectResponse
    {
        $this->importador->asignarManual($importacion, $fila, (int) $request->validated('colaborador_id'), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Colaborador asignado a la fila.']);
    }

    /**
     * La persona del Excel no existe en PEOPLE: RH la crea desde esta misma
     * fila (CLAUDE.md §74-81) en vez de quedar bloqueado en "no encontrado".
     */
    public function crearColaborador(CrearColaboradorImportacionRequest $request, ImportacionExcel $importacion, int $fila): RedirectResponse
    {
        $colaborador = $this->importador->registrarColaboradorDesdeFila($importacion, $fila, $request->validated(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => "Se creó a {$colaborador->nombreCompleto()} y se actualizó la fila."]);
    }

    /**
     * Auditoría de matches manuales (CLAUDE.md §67-69): RH confirma que la
     * asociación (manual o creada) es correcta, incluso si la importación
     * ya se aplicó — nunca cambia datos de la fila, solo deja constancia.
     */
    public function confirmarMatch(Request $request, ImportacionExcel $importacion, int $fila): RedirectResponse
    {
        abort_unless($request->user()->can('colaboradores.importar_salarios_vacaciones'), 403);
        $this->importador->confirmarMatch($importacion, $fila, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Asociación confirmada.']);
    }
}
