<?php

namespace App\Policies;

use App\Models\Sucursal;
use App\Models\User;

class SucursalPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('sucursales.administrar');
    }

    public function view(User $usuario, Sucursal $sucursal): bool
    {
        return $usuario->can('sucursales.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('sucursales.administrar');
    }

    public function update(User $usuario, Sucursal $sucursal): bool
    {
        return $usuario->can('sucursales.administrar');
    }

    public function delete(User $usuario, Sucursal $sucursal): bool
    {
        return $usuario->can('sucursales.administrar');
    }
}
