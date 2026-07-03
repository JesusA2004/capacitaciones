<?php

namespace App\Policies;

use App\Models\SesionEnVivo;
use App\Models\User;

class SesionEnVivoPolicy
{
    public function create(User $usuario): bool
    {
        return $usuario->can('sesiones.administrar');
    }

    public function update(User $usuario, SesionEnVivo $sesion): bool
    {
        return $usuario->can('sesiones.administrar');
    }

    public function delete(User $usuario, SesionEnVivo $sesion): bool
    {
        return $usuario->can('sesiones.administrar');
    }
}
