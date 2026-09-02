<?php

namespace App\Http\Controllers\Rh;

use App\Enums\EstadoVacante;
use App\Enums\MotivoVacante;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rh\ActualizarEstadoVacanteRequest;
use App\Http\Requests\Rh\StoreVacanteRequest;
use App\Http\Requests\Rh\UpdateVacanteRequest;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sucursal;
use App\Models\Vacante;
use App\Services\AlcanceOrganizacionalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VacanteController extends Controller
{
    public function __construct(private readonly AlcanceOrganizacionalService $alcance) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Vacante::class);

        $usuario = $request->user();

        $vacantes = $this->alcance
            ->limitarPorSucursal(
                Vacante::query()->with([
                    'empresa:id,nombre',
                    'sucursal:id,nombre',
                    'departamento:id,nombre',
                    'puesto:id,nombre',
                    'gerenteSolicitante:id,name,apellidos',
                    'responsableRh:id,name,apellidos',
                ])->withCount('candidatos'),
                $usuario,
            )
            ->when($request->integer('empresa_id'), fn ($query, $valor) => $query->where('empresa_id', $valor))
            ->when($request->integer('sucursal_id'), fn ($query, $valor) => $query->where('sucursal_id', $valor))
            ->when($request->integer('puesto_id'), fn ($query, $valor) => $query->where('puesto_id', $valor))
            ->when($request->string('estado')->toString(), fn ($query, string $estado) => $query->where('estado', $estado))
            ->orderByDesc('fecha_apertura')
            ->get();

        return Inertia::render('Rh/Vacantes/Index', [
            'vacantes' => $vacantes,
            'filtros' => $request->only('empresa_id', 'sucursal_id', 'puesto_id', 'estado'),
            'opciones' => [
                'empresas' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
                'sucursales' => Sucursal::query()->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']),
                'departamentos' => Departamento::query()->orderBy('nombre')->get(['id', 'nombre']),
                'puestos' => Puesto::query()->orderBy('nombre')->get(['id', 'nombre', 'departamento_id']),
                'motivos' => array_map(fn (MotivoVacante $m) => ['value' => $m->value, 'etiqueta' => $m->etiqueta()], MotivoVacante::cases()),
                'estados' => array_map(fn (EstadoVacante $e) => ['value' => $e->value, 'etiqueta' => $e->etiqueta()], EstadoVacante::cases()),
            ],
        ]);
    }

    public function store(StoreVacanteRequest $request): RedirectResponse
    {
        Vacante::create([
            ...$request->validated(),
            'creado_por' => $request->user()?->id,
        ]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante creada correctamente.']);
    }

    public function update(UpdateVacanteRequest $request, Vacante $vacante): RedirectResponse
    {
        $vacante->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante actualizada correctamente.']);
    }

    public function actualizarEstado(ActualizarEstadoVacanteRequest $request, Vacante $vacante): RedirectResponse
    {
        $vacante->update(['estado' => $request->validated('estado')]);

        return back()->with('toast', ['type' => 'success', 'message' => 'Estado de la vacante actualizado.']);
    }

    public function destroy(Vacante $vacante): RedirectResponse
    {
        $this->authorize('delete', $vacante);

        $vacante->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Vacante eliminada correctamente.']);
    }
}
