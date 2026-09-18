<?php

namespace App\Http\Controllers\Rh;

use App\Enums\CanalReclutamiento;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\StoreCampanaReclutamientoRequest;
use App\Http\Requests\Rh\UpdateCampanaReclutamientoRequest;
use App\Models\CampanaReclutamiento;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Services\Reclutamiento\CampanaReclutamientoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
                'canales' => array_map(
                    fn (CanalReclutamiento $c) => ['value' => $c->value, 'etiqueta' => $c->etiqueta()],
                    CanalReclutamiento::cases(),
                ),
            ],
        ]);
    }

    public function store(StoreCampanaReclutamientoRequest $request): RedirectResponse
    {
        CampanaReclutamiento::create([
            ...$request->validated(),
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Campaña registrada correctamente.']);
    }

    public function update(UpdateCampanaReclutamientoRequest $request, CampanaReclutamiento $campana): RedirectResponse
    {
        $campana->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Campaña actualizada correctamente.']);
    }

    public function destroy(Request $request, CampanaReclutamiento $campana): RedirectResponse
    {
        abort_unless($request->user()?->can('reclutamiento.campanas.administrar'), 403);

        $campana->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Campaña eliminada correctamente.']);
    }
}
