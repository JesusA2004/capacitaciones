<?php

namespace App\Policies;

use App\Models\Actividad;
use App\Models\User;

class ActividadPolicy
{
    public function create(User $usuario): bool
    {
        return $usuario->can('actividades.administrar');
    }

    public function update(User $usuario, Actividad $actividad): bool
    {
        return $usuario->can('actividades.administrar');
    }

    public function delete(User $usuario, Actividad $actividad): bool
    {
        return $usuario->can('actividades.administrar');
    }
}
