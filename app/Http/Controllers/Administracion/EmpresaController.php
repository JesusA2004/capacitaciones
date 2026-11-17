<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreEmpresaRequest;
use App\Http\Requests\Administracion\UpdateEmpresaRequest;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Services\Administracion\EmpresaService;
use App\Support\Consultas\EstadisticasActivoInactivo;
use App\Support\Paginacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catálogo de empresas. El alta/edición con logo vive en EmpresaService.
 */
class EmpresaController extends Controller
{
    public function __construct(private readonly EmpresaService $empresas) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Empresa::class);

        $empresas = Empresa::query()
            ->withCount('sucursales')
            ->when($request->string('busqueda')->toString(), function ($query, string $busqueda) {
                $query->where(function ($sub) use ($busqueda) {
                    $sub->where('nombre', 'like', "%{$busqueda}%")
                        ->orWhere('razon_social', 'like', "%{$busqueda}%")
                        ->orWhere('rfc', 'like', "%{$busqueda}%");
                });
            })
            ->orderBy('nombre')
            ->paginate(Paginacion::porPagina())
            ->withQueryString();

        $colaboradoresPorEmpresa = Colaborador::query()
            ->join('sucursales', 'sucursales.id', '=', 'colaboradores.sucursal_principal_id')
            ->where('colaboradores.estatus', 'activo')
            ->whereIn('sucursales.empresa_id', $empresas->getCollection()->pluck('id'))
            ->selectRaw('sucursales.empresa_id, count(*) as total')
            ->groupBy('sucursales.empresa_id')
            ->pluck('total', 'empresa_id');

        $empresas->getCollection()->transform(function (Empresa $empresa) use ($colaboradoresPorEmpresa) {
            $empresa->setAttribute('colaboradores_count', (int) ($colaboradoresPorEmpresa[$empresa->id] ?? 0));

            return $empresa;
        });

        return Inertia::render('Administracion/Empresas/Index', [
            'empresas' => $empresas,
            // Acciones del catálogo (config/permisos_modulos.php → empresas).
            'puede' => [
                'crear' => $request->user()->can('create', Empresa::class),
                'editar' => $request->user()->can('empresas.editar'),
                'eliminar' => $request->user()->can('empresas.eliminar'),
            ],
            'filtros' => $request->only('busqueda'),
            'estadisticas' => EstadisticasActivoInactivo::de(Empresa::query()),
        ]);
    }

    public function store(StoreEmpresaRequest $request): RedirectResponse
    {
        $this->empresas->crear($request->safe()->except('logo'), $request->file('logo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa creada correctamente.']);
    }

    public function update(UpdateEmpresaRequest $request, Empresa $empresa): RedirectResponse
    {
        $this->empresas->actualizar($empresa, $request->safe()->except('logo'), $request->file('logo'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa actualizada correctamente.']);
    }

    public function destroy(Empresa $empresa): RedirectResponse
    {
        $this->authorize('delete', $empresa);

        if ($empresa->sucursales()->exists()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'No se puede eliminar: la empresa tiene sucursales asociadas.',
            ]);
        }

        $empresa->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Empresa eliminada correctamente.']);
    }
}
