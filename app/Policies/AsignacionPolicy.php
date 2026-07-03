<?php

namespace App\Policies;

use App\Models\Asignacion;
use App\Models\User;

class AsignacionPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('asignaciones.ver');
    }

    public function view(User $usuario, Asignacion $asignacion): bool
    {
        return $usuario->can('asignaciones.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('asignaciones.crear');
    }

    public function cancelar(User $usuario, Asignacion $asignacion): bool
    {
        return $usuario->can('asignaciones.cancelar');
    }
}
