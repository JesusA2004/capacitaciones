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
}
