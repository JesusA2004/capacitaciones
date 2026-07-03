<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ClonarRolRequest;
use App\Http\Requests\Administracion\StoreRolRequest;
use App\Http\Requests\Administracion\UpdateRolRequest;
use App\Http\Resources\RolResource;
use App\Services\RolPermisoService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RolController extends Controller
{
    public function __construct(private readonly RolPermisoService $rolPermisoService) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Role::class);

        $roles = Role::query()
            ->withCount('users')
            ->with('permissions')
            ->orderBy('name')
            ->get();

        return Inertia::render('Administracion/Roles/Index', [
            'roles' => RolResource::collection($roles)->resolve(),
            'permisosAgrupados' => $this->rolPermisoService->permisosAgrupados(),
        ]);
    }

    public function store(StoreRolRequest $request): RedirectResponse
    {
        $this->rolPermisoService->crear($request->string('nombre')->value(), $request->input('permisos', []));

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol creado correctamente.']);
    }

    public function update(UpdateRolRequest $request, Role $rol): RedirectResponse
    {
        $this->rolPermisoService->actualizar($rol, $request->string('nombre')->value(), $request->input('permisos', []));

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol actualizado correctamente.']);
    }

    public function destroy(Role $rol): RedirectResponse
    {
        $this->authorize('delete', $rol);

        $this->rolPermisoService->eliminar($rol);

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol eliminado correctamente.']);
    }

    public function clonar(ClonarRolRequest $request, Role $rol): RedirectResponse
    {
        $this->rolPermisoService->clonar($rol, $request->string('nombre')->value());

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol clonado correctamente.']);
    }
}
