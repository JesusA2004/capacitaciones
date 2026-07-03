<?php

namespace App\Policies;

use App\Models\Puesto;
use App\Models\User;

class PuestoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('puestos.administrar');
    }

    public function view(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('puestos.administrar');
    }

    public function update(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar');
    }

    public function delete(User $usuario, Puesto $puesto): bool
    {
        return $usuario->can('puestos.administrar');
    }
}
