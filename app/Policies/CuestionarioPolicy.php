<?php

namespace App\Policies;

use App\Models\Cuestionario;
use App\Models\User;

class CuestionarioPolicy
{
    public function view(User $usuario, Cuestionario $cuestionario): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function update(User $usuario, Cuestionario $cuestionario): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function delete(User $usuario, Cuestionario $cuestionario): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }
}
