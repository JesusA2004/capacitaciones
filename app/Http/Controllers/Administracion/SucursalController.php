<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreSucursalRequest;
use App\Http\Requests\Administracion\UpdateSucursalRequest;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use App\Services\Headcount\HeadcountService;
use App\Services\Sucursales\SucursalDetalleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function __construct(
        private readonly HeadcountService $headcount,
        private readonly SucursalDetalleService $detalle,
    ) {}

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

        $sucursal->load('empresa:id,nombre');

        $plantillaPorPuesto = $this->headcount->resumenPorPuesto($sucursal->id);
        // Mismo criterio que HeadcountService::resumenPorSucursal(): por
        // puesto, "ocupada" nunca pasa de lo autorizado.
        $autorizada = (int) $plantillaPorPuesto->sum('plantilla_autorizada');
        $ocupada = (int) $plantillaPorPuesto->sum(fn (array $f) => min($f['plantilla_actual'], $f['plantilla_autorizada']));
        $totales = [
            'plantilla_autorizada' => $autorizada,
            'plantilla_actual' => $ocupada,
            'vacantes' => (int) $plantillaPorPuesto->sum('faltante'),
            'cobertura' => $autorizada > 0 ? round(($ocupada / $autorizada) * 100, 1) : 0.0,
            // Personas en puestos SIN plantilla autorizada en esta sucursal:
            // se reportan (auditable) para que RH las reubique o capture la
            // plantilla, no se cuentan como cobertura.
            'fuera_de_plantilla' => (int) $plantillaPorPuesto->filter(fn (array $f) => $f['plantilla_autorizada'] === 0)->sum('plantilla_actual'),
        ];

        return Inertia::render('Administracion/Sucursales/Show', [
            'sucursal' => $sucursal,
            'plantillaPorPuesto' => $plantillaPorPuesto,
            'totales' => $totales,
            // Quien responde por la sucursal es su Gerente de Sucursal: no
            // hay otra figura de "responsable".
            'gerente' => $this->detalle->gerente($sucursal),
            'porDepartamento' => $this->detalle->porDepartamento($sucursal),
            'departamentos' => $sucursal->colaboradores()->where('estatus', 'activo')->whereNotNull('departamento_id')->distinct('departamento_id')->count('departamento_id'),
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
