<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolPermisoService
{
    /**
     * @param  array<int, string>  $permisos
     */
    public function crear(string $nombre, array $permisos): RoleContract
    {
        $rol = Role::create(['name' => $nombre, 'guard_name' => 'web']);
        $rol->syncPermissions($permisos);

        return $rol;
    }

    /**
     * @param  array<int, string>  $permisos
     */
    public function actualizar(Role $rol, string $nombre, array $permisos): Role
    {
        $rol->update(['name' => $nombre]);
        $rol->syncPermissions($permisos);

        return $rol->fresh() ?? $rol;
    }

    public function clonar(Role $rol, string $nuevoNombre): RoleContract
    {
        $nuevo = Role::create(['name' => $nuevoNombre, 'guard_name' => $rol->guard_name]);
        $nuevo->syncPermissions($rol->permissions->pluck('name'));

        return $nuevo;
    }

    public function eliminar(Role $rol): void
    {
        $rol->delete();
    }

    /**
     * Roles para la pantalla "Roles y permisos", con el número de usuarios y
     * los NOMBRES de sus permisos en `permisos_nombres`. Los nombres se leen
     * con un join plano sobre role_has_permissions: hidratar ~1,300 modelos
     * Permission (con pivote) solo para sacar su nombre costaba ~170 ms.
     *
     * @return EloquentCollection<int, Role>
     */
    public function rolesParaListado(): EloquentCollection
    {
        $tablas = config('permission.table_names');
        $pivote = sprintf('%s', $tablas['role_has_permissions'] ?? 'role_has_permissions');
        $permisos = sprintf('%s', $tablas['permissions'] ?? 'permissions');

        $nombresPorRol = DB::table($pivote)
            ->join($permisos, sprintf('%s.id', $permisos), '=', sprintf('%s.permission_id', $pivote))
            ->orderBy(sprintf('%s.name', $permisos))
            ->get([sprintf('%s.role_id', $pivote), sprintf('%s.name', $permisos)])
            ->groupBy('role_id')
            ->map(fn (Collection $filas) => $filas->pluck('name')->values());

        $roles = Role::query()->withCount('users')->orderBy('name')->get();

        foreach ($roles as $rol) {
            $rol->setAttribute('permisos_nombres', $nombresPorRol->get($rol->id, collect()));
        }

        return $roles;
    }

    /**
     * @param  array<int, string>  $roles
     */
    public function asignarRoles(User $usuario, array $roles): void
    {
        $usuario->syncRoles($roles);
    }

    /**
     * Agrupa los permisos por su modulo (prefijo antes del primer punto)
     * para presentarlos organizados en el formulario de roles.
     *
     * @return Collection<string, EloquentCollection<int, Permission>>
     */
    public function permisosAgrupados(): Collection
    {
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permiso) => explode('.', $permiso->name)[0]);
    }
}
