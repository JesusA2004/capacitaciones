<?php

namespace App\Http\Controllers\Rh;

use App\Enums\CanalReclutamiento;
use App\Enums\TipoCostoReclutamiento;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreCampanaReclutamientoRequest;
use App\Http\Requests\Rh\UpdateCampanaReclutamientoRequest;
use App\Models\CampanaReclutamiento;
use App\Models\CampanaReclutamientoAdjunto;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Reclutamiento\CampanaReclutamientoService;
use App\Services\Reclutamiento\CostoReclutamientoService;
use App\Services\Vacantes\VacantesListadoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Gasto de campañas de reclutamiento por canal/periodo (ver
 * App\Services\Reclutamiento\CampanaReclutamientoService). Autorización por
 * permiso propio (reclutamiento.campanas.ver/administrar, ver
 * RolesYPermisosSeeder) — este módulo no tiene la granularidad de
 * Vacantes/Candidatos (ver_todos/ver_sucursal), porque el gasto de
 * campañas no es información sensible por sucursal.
 */
class CampanaReclutamientoController extends Controller
{
    private const FILTROS = ['empresa_id', 'sucursal_id', 'departamento_id', 'puesto_id', 'canal'];

    public function __construct(
        private readonly CampanaReclutamientoService $servicio,
        private readonly CostoReclutamientoService $costos,
        private readonly VacantesListadoService $vacantesListado,
    ) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario?->can('reclutamiento.campanas.ver'), 403);

        $mes = $request->integer('mes') ?: (int) now()->month;
        $anio = $request->integer('anio') ?: (int) now()->year;
        $filtros = $request->only(self::FILTROS);

        $consulta = CampanaReclutamiento::query()
            ->with([
                'empresa:id,nombre',
                'sucursal:id,nombre',
                'departamento:id,nombre',
                'puesto:id,nombre',
                'vacante:id,puesto_id,sucursal_id,estado',
                'creadoPor:id,name,apellidos',
                'responsable:id,name,apellidos',
                'adjuntos',
            ])
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->when($filtros['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn ($q, $v) => $q->where('departamento_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn ($q, $v) => $q->where('puesto_id', $v))
            ->when($filtros['canal'] ?? null, fn ($q, $v) => $q->where('canal', $v))
            ->orderByDesc('created_at');

        // Totales del periodo (todas las campañas filtradas, no solo la
        // página) y, por campaña, cuánto costó cada colaborador contratado.
        $costos = $this->costos->porCampana((clone $consulta)->get());
        $campanas = $consulta->paginate(15)->withQueryString();
        $campanas->getCollection()->transform(function (CampanaReclutamiento $campana) use ($costos) {
            $campana->setAttribute('resultado', $costos['por_campana'][$campana->id] ?? null);
            $campana->setAttribute('adjuntos_lista', $campana->adjuntos->map(fn (CampanaReclutamientoAdjunto $a) => [
                'id' => $a->id,
                'nombre' => $a->nombre_original,
                'mime' => $a->mime,
                'url' => route('rh.campanas.adjuntos.show', [$campana, $a]),
            ])->values()->all());
            $campana->unsetRelation('adjuntos');

            return $campana;
        });

        return Inertia::render('Rh/Campanas/Index', [
            'campanas' => $campanas,
            'totales' => ['gasto' => $costos['gasto'], 'contratados' => $costos['contratados'], 'costo_por_colaborador' => $costos['costo_por_colaborador'], 'campanas' => count($costos['por_campana'])],
            'filtros' => [
                ...$request->only(self::FILTROS),
                'mes' => $mes,
                'anio' => $anio,
            ],
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
                'tiposCosto' => TipoCostoReclutamiento::opciones(),
                // Quién puede llevar una campaña: quien la administra.
                'responsables' => User::permission('reclutamiento.campanas.administrar')->orderBy('name')->get(['id', 'name', 'apellidos']),
                // Misma fuente de verdad que Candidatos/Vacantes
                // (VacantesListadoService, CLAUDE.md §2): vacantes reales
                // primero; se completan con las ya ligadas a una campaña
                // existente (aunque hoy estén cubiertas/canceladas) para no
                // romper el histórico de gasto de campañas pasadas.
                'vacantes' => $this->vacantesSeleccionables($usuario),
                'canales' => array_map(
                    fn (CanalReclutamiento $c) => ['value' => $c->value, 'etiqueta' => $c->etiqueta()],
                    CanalReclutamiento::cases(),
                ),
            ],
        ]);
    }

    /**
     * @return list<array{id: int, etiqueta: string}>
     */
    private function vacantesSeleccionables(User $usuario): array
    {
        $reales = $this->vacantesListado->consulta($usuario)
            ->with(['puesto:id,nombre', 'sucursal:id,nombre'])
            ->latest('fecha_apertura')
            ->limit(300)
            ->get();

        $historicasIds = CampanaReclutamiento::query()
            ->whereNotNull('vacante_id')
            ->whereNotIn('vacante_id', $reales->pluck('id'))
            ->distinct()
            ->pluck('vacante_id');

        $historicas = Vacante::query()
            ->whereIn('id', $historicasIds)
            ->with(['puesto:id,nombre', 'sucursal:id,nombre'])
            ->get();

        return array_values($reales->concat($historicas)
            ->map(fn (Vacante $v) => ['id' => $v->id, 'etiqueta' => sprintf('#%d · %s · %s · %s', $v->id, $v->puesto->nombre ?? 'Sin puesto', $v->sucursal->nombre ?? 'Sin sucursal', $v->estado->etiqueta())])
            ->all());
    }

    public function store(StoreCampanaReclutamientoRequest $request): RedirectResponse
    {
        $this->servicio->guardar($request->validated(), null, $request->user()?->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'Campaña registrada correctamente.']);
    }

    public function update(UpdateCampanaReclutamientoRequest $request, CampanaReclutamiento $campana): RedirectResponse
    {
        $this->servicio->guardar($request->validated(), $campana, $request->user()?->id);

        return back()->with('toast', ['type' => 'success', 'message' => 'Campaña actualizada correctamente.']);
    }

    /**
     * Excel de costo por contratación (resumen ANSI/SHRM + detalle por
     * persona contratada) del rango ?desde&hasta.
     */
    public function exportarCostos(Request $request): BinaryFileResponse
    {
        $costos = $this->costos;
        abort_unless($request->user()?->can('reclutamiento.campanas.ver'), 403);
        $desde = Carbon::parse($request->date('desde')?->toDateString() ?? now()->startOfMonth()->toDateString());
        $hasta = Carbon::parse($request->date('hasta')?->toDateString() ?? now()->toDateString());
        $resumen = $costos->resumen($desde, $hasta);
        $filas = array_map(fn (array $f) => [$f['nombre'], $f['puesto'], $f['sucursal'], $f['fuente'], $f['contratado_en'], $f['costo_directo'], $f['prorrateo_general'], $f['costo_total']], $costos->porContratado($desde, $hasta));
        $filas[] = ['TOTAL PERIODO (ANSI/SHRM)', null, null, null, null, $resumen['gasto_total'], $resumen['contrataciones'], $resumen['costo_por_contratacion']];

        return Excel::download(
            new ReporteRhExport('Costo por contratación', ['Persona', 'Puesto', 'Sucursal', 'Fuente', 'Contratado', 'Costo directo', 'Prorrateo general', 'Costo total'], $filas),
            sprintf('costo-contratacion-%s-%s.xlsx', $desde->toDateString(), $hasta->toDateString()),
        );
    }

    public function destroy(Request $request, CampanaReclutamiento $campana): RedirectResponse
    {
        abort_unless($request->user()?->can('reclutamiento.campanas.administrar'), 403);

        $campana->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Campaña eliminada correctamente.']);
    }

    /**
     * Arte/PDF de la campaña desde el NAS privado (solo autenticado y con permiso).
     */
    public function adjunto(Request $request, CampanaReclutamiento $campana, CampanaReclutamientoAdjunto $adjunto): StreamedResponse
    {
        abort_unless($request->user()?->can('reclutamiento.campanas.ver'), 403);
        abort_unless($adjunto->campana_reclutamiento_id === $campana->id, 404);

        return $this->servicio->respuestaAdjunto($adjunto);
    }

    public function eliminarAdjunto(Request $request, CampanaReclutamiento $campana, CampanaReclutamientoAdjunto $adjunto): RedirectResponse
    {
        abort_unless($request->user()?->can('reclutamiento.campanas.administrar'), 403);
        abort_unless($adjunto->campana_reclutamiento_id === $campana->id, 404);

        $this->servicio->eliminarAdjunto($adjunto);

        return back()->with('toast', ['type' => 'success', 'message' => 'Archivo eliminado.']);
    }
}
