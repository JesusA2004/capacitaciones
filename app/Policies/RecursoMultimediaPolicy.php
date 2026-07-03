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

    /**
     * Un recurso con acceso restringido (evidencia de actividad/cuestionario)
     * nunca se autoriza por el permiso plano de biblioteca: solo su
     * propietario o quien tenga permiso explícito de revisión puede verlo.
     * Ver docs/AUDITORIA_CUMPLIMIENTO.md sección 12.
     */
    public function view(User $usuario, RecursoMultimedia $recurso): bool
    {
        if ($recurso->acceso_restringido) {
            return $usuario->id === $recurso->propietario_id || $usuario->can('respuestas.ver');
        }

        return $usuario->can('multimedia.administrar');
    }

    public function create(User $usuario): bool
    {
        return $usuario->can('multimedia.administrar');
    }

    /**
     * Las evidencias privadas no se eliminan desde la biblioteca general:
     * se gestionan únicamente a través del flujo de la entrega/respuesta
     * que las generó, para no perder el rastro de la calificación.
     */
    public function delete(User $usuario, RecursoMultimedia $recurso): bool
    {
        if ($recurso->acceso_restringido) {
            return false;
        }

        return $usuario->can('multimedia.administrar');
    }
}
