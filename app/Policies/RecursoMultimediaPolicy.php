<?php

namespace App\Policies;

use App\Models\RecursoMultimedia;
use App\Models\User;

class RecursoMultimediaPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('multimedia.administrar');
    }

    public function view(User $usuario, RecursoMultimedia $recurso): bool
    {
        return $usuario->can('multimedia.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('multimedia.administrar');
    }

    public function delete(User $usuario, RecursoMultimedia $recurso): bool
    {
        return $usuario->can('multimedia.administrar');
    }
}
