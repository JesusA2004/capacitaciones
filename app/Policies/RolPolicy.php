<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Permission\Models\Role;

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
        return $user->can('roles.administrar');
    }

    public function delete(User $user, Role $rol): bool
    {
        return $user->can('roles.administrar') && $rol->name !== 'super_admin';
    }

    public function clonar(User $user, Role $rol): bool
    {
        return $user->can('roles.administrar');
    }
}
