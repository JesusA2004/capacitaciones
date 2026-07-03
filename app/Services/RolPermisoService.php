<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
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
