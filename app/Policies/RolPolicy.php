<?php

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RolesYPermisosSeeder;
use Spatie\Permission\Models\Role;

/**
 * «Roles y permisos»: todo exige roles.administrar. super_admin queda
 * bloqueado como «todo» (no se edita ni se elimina: tiene el catálogo
 * operativo completo por Gate::before y por el seeder).
 */
class RolPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('roles.administrar');
    }

    public function view(User $user, Role $rol): bool
    {
        return $user->can('roles.administrar');
    }

    public function create(User $user): bool
    {
        return $user->can('roles.administrar');
    }

    public function update(User $user, Role $rol): bool
    {
        return $user->can('roles.administrar') && $rol->name !== 'super_admin';
    }

    /**
     * Los roles del seeder no se eliminan: el código los reconoce por nombre
     * y `people:sincronizar-permisos` los volvería a crear en el siguiente
     * deploy. Solo los roles creados a mano.
     */
    public function delete(User $user, Role $rol): bool
    {
        return $user->can('roles.administrar')
            && $rol->name !== 'super_admin'
            && ! array_key_exists((string) $rol->name, RolesYPermisosSeeder::permisosBasePorRol());
    }

    public function clonar(User $user, Role $rol): bool
    {
        return $user->can('roles.administrar');
    }
}
