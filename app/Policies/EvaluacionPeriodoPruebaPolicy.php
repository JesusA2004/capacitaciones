<?php

namespace App\Policies;

use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\JerarquiaColaboradorService;

/**
 * La evaluación la captura el jefe inmediato/gerente del colaborador (según
 * la estructura jerárquica real) o RH con permiso de autorizar; la autoriza
 * RH/Dirección. Nadie evalúa ni autoriza su propia evaluación.
 */
class EvaluacionPeriodoPruebaPolicy
{
    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly JerarquiaColaboradorService $jerarquia,
    ) {}

    public function ver(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->esEvaluador($usuario, $evaluacion)
            || ($usuario->can('evaluaciones.ver') && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador));
    }

    public function capturar(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        if ($usuario->colaborador_id === $evaluacion->colaborador_id) {
            return false;
        }

        return $this->esEvaluador($usuario, $evaluacion)
            || ($usuario->can('evaluaciones.autorizar') && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador));
    }

    public function autorizar(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $usuario->can('evaluaciones.autorizar')
            && $usuario->colaborador_id !== $evaluacion->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador);
    }

    private function esEvaluador(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        if ($usuario->colaborador_id === null) {
            return false;
        }

        return $evaluacion->evaluador_colaborador_id === $usuario->colaborador_id
            || $this->jerarquia->usuarioEsJefeDe($usuario, $evaluacion->colaborador);
    }
}
