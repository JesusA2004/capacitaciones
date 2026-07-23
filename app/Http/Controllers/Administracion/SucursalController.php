<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\StoreSucursalRequest;
use App\Http\Requests\Administracion\UpdateSucursalRequest;
use App\Models\Empresa;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SucursalController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Sucursal::class);

        $sucursales = Sucursal::query()
            ->withCount('usuarios')
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
