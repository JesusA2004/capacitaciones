<?php

namespace App\Policies;

use App\Models\DocumentTemplate;
use App\Models\User;

class DocumentTemplatePolicy
{
    public function viewAny(User $usuario): bool
    {
        return $usuario->can('plantillas.ver');
    }

    public function view(User $usuario, DocumentTemplate $plantilla): bool
    {
        return $usuario->can('plantillas.ver');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('plantillas.crear');
    }

    public function update(User $usuario, DocumentTemplate $plantilla): bool
    {
        return $usuario->can('plantillas.editar');
    }

    public function delete(User $usuario, DocumentTemplate $plantilla): bool
    {
        return $usuario->can('plantillas.eliminar');
    }

    public function generar(User $usuario, DocumentTemplate $plantilla): bool
    {
        return $usuario->can('plantillas.generar');
    }
}
