<?php

namespace App\Http\Controllers\Rh;

use App\Enums\CanalReclutamiento;
use App\Enums\TipoCostoReclutamiento;
use App\Exports\ReporteRhExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreCampanaReclutamientoRequest;
use App\Http\Requests\Rh\UpdateCampanaReclutamientoRequest;
use App\Models\CampanaReclutamiento;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\Reclutamiento\CampanaReclutamientoService;
use App\Services\Reclutamiento\CostoReclutamientoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    public function __construct(private readonly CampanaReclutamientoService $servicio) {}

    public function index(Request $request): Response
    {
        $usuario = $request->user();
        abort_unless($usuario?->can('reclutamiento.campanas.ver'), 403);

        $mes = $request->integer('mes') ?: (int) now()->month;
        $anio = $request->integer('anio') ?: (int) now()->year;
        $filtros = $request->only(self::FILTROS);

        $campanas = CampanaReclutamiento::query()
            ->with([
                'empresa:id,nombre',
                'sucursal:id,nombre',
                'departamento:id,nombre',
                'puesto:id,nombre',
                'vacante:id,puesto_id,sucursal_id,estado',
                'creadoPor:id,name,apellidos',
            ])
            ->where('mes', $mes)
            ->where('anio', $anio)
            ->when($filtros['empresa_id'] ?? null, fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($filtros['sucursal_id'] ?? null, fn ($q, $v) => $q->where('sucursal_id', $v))
            ->when($filtros['departamento_id'] ?? null, fn ($q, $v) => $q->where('departamento_id', $v))
            ->when($filtros['puesto_id'] ?? null, fn ($q, $v) => $q->where('puesto_id', $v))
            ->when($filtros['canal'] ?? null, fn ($q, $v) => $q->where('canal', $v))
            ->orderByDesc('created_at')
            ->paginate(15)
            ->withQueryString();

        return Inertia::render('Rh/Campanas/Index', [
            'campanas' => $campanas,
            'kpis' => $this->servicio->resumenPeriodo($mes, $anio, $filtros),
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
                'vacantes' => Vacante::query()->with(['puesto:id,nombre', 'sucursal:id,nombre'])->latest('fecha_apertura')->limit(300)->get()
                    ->map(fn (Vacante $v) => ['id' => $v->id, 'etiqueta' => sprintf('#%d · %s · %s · %s', $v->id, $v->puesto->nombre ?? 'Sin puesto', $v->sucursal->nombre ?? 'Sin sucursal', $v->estado->etiqueta())])
                    ->values(),
                'canales' => array_map(
                    fn (CanalReclutamiento $c) => ['value' => $c->value, 'etiqueta' => $c->etiqueta()],
                    CanalReclutamiento::cases(),
                ),
            ],
        ]);
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
    public function exportarCostos(Request $request, CostoReclutamientoService $costos): BinaryFileResponse
    {
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
}
