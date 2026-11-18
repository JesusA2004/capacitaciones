<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Http\Requests\Administracion\ClonarRolRequest;
use App\Http\Requests\Administracion\StoreRolRequest;
use App\Http\Requests\Administracion\UpdateRolRequest;
use App\Http\Resources\RolResource;
use App\Services\Notificaciones\PoliticaNotificacionRolService;
use App\Services\Permisos\CatalogoPermisosService;
use App\Services\Permisos\PermisosEspecialesService;
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
            // Borrado físico de roles: exclusivo de super_admin (RolPolicy::delete).
            'puedeEliminarRoles' => $usuario->hasRole('super_admin'),
            // Permisos especiales por persona (acceso desde esta pantalla).
            'puedePermisosEspeciales' => app(PermisosEspecialesService::class)->puedeAdministrar($usuario),
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

    /**
     * Borrado FÍSICO (solo super_admin): confirma escribiendo el nombre del
     * rol y, si alguien lo tiene, a qué rol pasan esas personas.
     */
    public function destroy(Request $request, Role $rol): RedirectResponse
    {
        $this->authorize('delete', $rol);

        $datos = $request->validate([
            'confirmacion' => ['required', 'string', 'in:'.$rol->name],
            'reasignar_a' => ['nullable', 'integer', 'exists:roles,id'],
        ], [
            'confirmacion.in' => sprintf('Escribe exactamente «%s» para confirmar.', $rol->name),
            'confirmacion.required' => sprintf('Escribe «%s» para confirmar.', $rol->name),
        ]);

        $destino = isset($datos['reasignar_a']) ? Role::query()->where('id', (int) $datos['reasignar_a'])->first() : null;
        $movidos = $this->rolPermisoService->eliminarDefinitivamente($rol, $destino, $request->user());

        return back()->with('toast', [
            'type' => 'success',
            'message' => $movidos > 0 && $destino !== null
                ? sprintf('Rol eliminado. %d persona(s) pasaron a «%s».', $movidos, $destino->name)
                : 'Rol eliminado definitivamente.',
        ]);
    }

    /**
     * Borrado LÓGICO: deja de otorgar permisos y no se ofrece al asignar.
     */
    public function desactivar(Request $request, Role $rol): RedirectResponse
    {
        $this->authorize('desactivar', $rol);

        $datos = $request->validate(['motivo' => ['nullable', 'string', 'max:255']]);
        $this->rolPermisoService->desactivar($rol, $request->user(), $datos['motivo'] ?? null);

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('«%s» desactivado: ya no otorga permisos ni se puede asignar. Puedes reactivarlo cuando quieras.', $rol->name)]);
    }

    public function reactivar(Request $request, Role $rol): RedirectResponse
    {
        $this->authorize('desactivar', $rol);

        $this->rolPermisoService->reactivar($rol, $request->user());

        return back()->with('toast', ['type' => 'success', 'message' => sprintf('«%s» reactivado: vuelve a otorgar sus permisos.', $rol->name)]);
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
