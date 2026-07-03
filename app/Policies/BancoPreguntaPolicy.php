<?php

namespace App\Policies;

use App\Models\BancoPregunta;
use App\Models\User;

class BancoPreguntaPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function view(User $usuario, BancoPregunta $banco): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function update(User $usuario, BancoPregunta $banco): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }

    public function delete(User $usuario, BancoPregunta $banco): bool
    {
        return $usuario->can('cuestionarios.administrar');
    }
}
