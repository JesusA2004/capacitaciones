<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ClonarRolRequest;
use App\Http\Requests\Administracion\StoreRolRequest;
use App\Http\Requests\Administracion\UpdateRolRequest;
use App\Http\Resources\RolResource;
use App\Services\Notificaciones\PoliticaNotificacionRolService;
use App\Services\Permisos\CatalogoPermisosService;
use App\Services\RolPermisoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class RolController extends Controller
{
    public function __construct(
        private readonly RolPermisoService $rolPermisoService,
        private readonly CatalogoPermisosService $catalogo,
        private readonly PoliticaNotificacionRolService $politica,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Role::class);

        $usuario = $request->user();
        $roles = $this->rolPermisoService->rolesParaListado();
        $administra = $usuario->can('roles.administrar');
        $editaAvisos = $usuario->can('notificaciones_rol.editar');

        return Inertia::render('Administracion/Roles/Index', [
            // Sin roles.administrar se ven los roles, no sus permisos.
            'roles' => array_map(
                fn (array $rol): array => $administra ? $rol : [...$rol, 'permisos' => []],
                RolResource::collection($roles)->resolve(),
            ),
            // Matriz por etapa del sidebar → módulo → acceso / alcance /
            // acciones (config/permisos_modulos.php) + «Otros permisos».
            'catalogo' => $this->catalogo->paraPantalla(),
            'puedeAdministrarRoles' => $administra,
            // Checklist de avisos por rol (config/notificaciones_catalogo.php).
            'checklistNotificaciones' => $editaAvisos ? $this->politica->matrizParaPantalla(collect($roles)) : null,
            'historialNotificaciones' => $editaAvisos
                ? collect($roles)->mapWithKeys(fn (Role $rol): array => [$rol->id => $this->politica->historial($rol, 30)])->all()
                : [],
        ]);
    }

    public function store(StoreRolRequest $request): RedirectResponse
    {
        $this->rolPermisoService->crear($request->string('nombre')->value(), $this->permisos($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol creado correctamente.']);
    }

    public function update(UpdateRolRequest $request, Role $rol): RedirectResponse
    {
        $this->rolPermisoService->actualizar($rol, $request->string('nombre')->value(), $this->permisos($request), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol actualizado correctamente.']);
    }

    public function destroy(Request $request, Role $rol): RedirectResponse
    {
        $this->authorize('delete', $rol);

        $this->rolPermisoService->eliminar($rol, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol eliminado correctamente.']);
    }

    public function clonar(ClonarRolRequest $request, Role $rol): RedirectResponse
    {
        $this->rolPermisoService->clonar($rol, $request->string('nombre')->value(), $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => 'Rol clonado correctamente.']);
    }

    /**
     * @return list<string>
     */
    private function permisos(Request $request): array
    {
        return array_values(array_map('strval', (array) $request->input('permisos', [])));
    }
}
