<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreSucursalRequest;
use App\Http\Requests\Administracion\UpdateSucursalRequest;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function __construct(private readonly HeadcountService $headcount) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sucursal::class);

        $sucursales = Sucursal::query()
            ->withCount(['colaboradores' => fn ($q) => $q->where('estatus', 'activo')])
            ->with(['responsable:id,name,apellidos', 'empresa:id,nombre'])
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('clave', 'like', "%{$busqueda}%");
                });
            })
            ->when($request->integer('empresa_id'), fn ($query, int $empresaId) => $query->where('empresa_id', $empresaId))
            ->orderBy('nombre')
            ->paginate(15)
            ->withQueryString();

        $plantillaPorSucursal = $this->headcount->resumenPorSucursal()->keyBy('sucursal_id');
        $sucursales->getCollection()->transform(function (Sucursal $sucursal) use ($plantillaPorSucursal) {
            $fila = $plantillaPorSucursal->get($sucursal->id);
            $sucursal->setAttribute('plantilla_permitida', (int) ($fila['plantilla_autorizada'] ?? 0));
            $sucursal->setAttribute('plantilla_vacantes', (int) ($fila['vacantes'] ?? 0));

            return $sucursal;
        });

        return Inertia::render('Administracion/Sucursales/Index', [
            'sucursales' => $sucursales,
            'filtros' => $request->only('busqueda', 'empresa_id'),
            'responsablesDisponibles' => User::query()->orderBy('name')->get(['id', 'name', 'apellidos']),
            'empresasDisponibles' => Empresa::query()->orderBy('nombre')->get(['id', 'nombre']),
            'estadisticas' => [
                'total' => Sucursal::count(),
                'activos' => Sucursal::where('activo', true)->count(),
                'inactivos' => Sucursal::where('activo', false)->count(),
            ],
        ]);
    }

    /**
     * Detalle de sucursal: plantilla por puesto (permitida/cubierta/
     * vacantes/cobertura), igual que la sección "Plantilla" del encargo.
     */
    public function show(Sucursal $sucursal): Response
    {
        $this->authorize('view', $sucursal);

        $sucursal->load(['empresa:id,nombre', 'responsable:id,name,apellidos']);

        $plantillaPorPuesto = $this->headcount->resumenPorPuesto($sucursal->id);
        $totales = [
            'plantilla_autorizada' => (int) $plantillaPorPuesto->sum('plantilla_autorizada'),
            'plantilla_actual' => (int) $plantillaPorPuesto->sum('plantilla_actual'),
        ];
        $totales['vacantes'] = max($totales['plantilla_autorizada'] - $totales['plantilla_actual'], 0);
        $totales['cobertura'] = $totales['plantilla_autorizada'] > 0
            ? round(($totales['plantilla_actual'] / $totales['plantilla_autorizada']) * 100, 1)
            : 0.0;

        return Inertia::render('Administracion/Sucursales/Show', [
            'sucursal' => $sucursal,
            'plantillaPorPuesto' => $plantillaPorPuesto,
            'totales' => $totales,
            'departamentos' => $sucursal->colaboradores()->where('estatus', 'activo')->distinct()->pluck('departamento_id')->count(),
        ]);
    }

    public function store(StoreSucursalRequest $request): RedirectResponse
    {
        Sucursal::create($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal creada correctamente.']);
    }

    public function update(UpdateSucursalRequest $request, Sucursal $sucursal): RedirectResponse
    {
        $sucursal->update($request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal actualizada correctamente.']);
    }

    public function destroy(Sucursal $sucursal): RedirectResponse
    {
        $this->authorize('delete', $sucursal);

        $sucursal->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Sucursal eliminada correctamente.']);
    }
}
