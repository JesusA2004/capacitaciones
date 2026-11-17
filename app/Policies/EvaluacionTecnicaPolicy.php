<?php

namespace App\Policies;

use App\Enums\QuienContestaEvaluacionTecnica;
use App\Models\EvaluacionTecnica;
use App\Models\User;
use App\Services\EvaluacionesTecnicas\EvaluacionTecnicaService;

/**
 * Evaluaciones técnicas («Seguimiento N»):
 *  - ver el tablero: evaluaciones_tecnicas.ver (alcance del módulo);
 *  - configurar calendario y formularios: evaluaciones_tecnicas.configurar;
 *  - contestar: el propio colaborador si es su examen; si la contesta el
 *    jefe directo, el jefe que le asignó el organigrama o quien tenga
 *    evaluaciones_tecnicas.aplicar con alcance sobre esa persona (nunca la
 *    suya propia).
 * Sin bypass de super_admin en `contestar`: un examen lo contesta su dueño.
 */
class EvaluacionTecnicaPolicy
{
    public function __construct(private readonly EvaluacionTecnicaService $evaluaciones) {}

    public function viewAny(User $usuario): bool
    {
        return $usuario->can('evaluaciones_tecnicas.ver');
    }

    public function configurar(User $usuario): bool
    {
        return $usuario->can('evaluaciones_tecnicas.configurar');
    }

    public function view(User $usuario, EvaluacionTecnica $evaluacion): bool
    {
        return $this->esPropia($usuario, $evaluacion) || $this->verRespuestas($usuario, $evaluacion);
    }

    /** Respuestas, correctas y comentarios: evaluador o quien la ve con alcance. */
    public function verRespuestas(User $usuario, EvaluacionTecnica $evaluacion): bool
    {
        return $this->esEvaluador($usuario, $evaluacion)
            || ($usuario->can('evaluaciones_tecnicas.ver') && $this->evaluaciones->alcanza($usuario, $evaluacion));
    }

    public function contestar(User $usuario, EvaluacionTecnica $evaluacion): bool
    {
        if (! $evaluacion->estaPendiente()) {
            return false;
        }

        if ($evaluacion->contesta === QuienContestaEvaluacionTecnica::Colaborador) {
            return $this->esPropia($usuario, $evaluacion);
        }

        return ! $this->esPropia($usuario, $evaluacion) && (
            $this->esEvaluador($usuario, $evaluacion)
            || ($usuario->can('evaluaciones_tecnicas.aplicar') && $this->evaluaciones->alcanza($usuario, $evaluacion))
        );
    }

    private function esPropia(User $usuario, EvaluacionTecnica $evaluacion): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $evaluacion->colaborador_id;
    }

    private function esEvaluador(User $usuario, EvaluacionTecnica $evaluacion): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $evaluacion->evaluador_colaborador_id;
    }
}
