<?php

namespace App\Policies;

use App\Models\Curso;
use App\Models\User;

class CursoPolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('cursos.ver');
    }

    public function view(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('cursos.crear');
    }

    public function update(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.editar');
    }

    public function delete(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.eliminar');
    }

    public function publicar(User $usuario, Curso $curso): bool
    {
        return $usuario->can('cursos.publicar');
    }
}
