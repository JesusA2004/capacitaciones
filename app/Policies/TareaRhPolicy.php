<?php

namespace App\Policies;

use App\Models\TareaRh;
use App\Models\User;
use App\Services\Tareas\TareaService;

class TareaRhPolicy
{
    public function __construct(private readonly TareaService $tareas) {}

    public function ver(User $usuario, TareaRh $tarea): bool
    {
        return $this->tareas->esDestinatario($usuario, $tarea);
    }

    public function resolver(User $usuario, TareaRh $tarea): bool
    {
        return $tarea->resuelta_en === null && $this->tareas->esDestinatario($usuario, $tarea);
    }

    /**
     * «Quitar de mi lista» / «Restaurar»: solo sobre un pendiente abierto
     * que le llega a esa cuenta (docs/PENDIENTES.md).
     */
    public function descartar(User $usuario, TareaRh $tarea): bool
    {
        return $tarea->resuelta_en === null && $this->tareas->esDestinatario($usuario, $tarea);
    }

    /**
     * «Marcar como hecho» (cierra para todos): solo pendientes informativos
     * o asignados a su propia cuenta. Los demás se cierran solos al hacer
     * la acción en su pantalla.
     */
    public function marcarHecha(User $usuario, TareaRh $tarea): bool
    {
        return $this->resolver($usuario, $tarea)
            && ($tarea->tipo->esInformativo() || $tarea->asignado_user_id === $usuario->id);
    }
}
