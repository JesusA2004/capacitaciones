<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\JerarquiaColaboradorService;

/**
 * La evaluación la captura el jefe inmediato/gerente del colaborador (según
 * la estructura jerárquica real) o RH con permiso de autorizar; la autoriza
 * RH/Dirección. Nadie evalúa ni autoriza su propia evaluación — salvo
 * super_admin, ver ConSuperAdminOperativo.
 */
class EvaluacionPeriodoPruebaPolicy
{
    use ConSuperAdminOperativo;

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly JerarquiaColaboradorService $jerarquia,
    ) {}

    public function ver(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->esEvaluador($usuario, $evaluacion)
            || ($usuario->can('evaluaciones.ver') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $evaluacion->colaborador, 'evaluaciones'));
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

    /** Devolver al jefe para corrección: autorizar + la acción evaluaciones.devolver. */
    public function devolver(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->autorizar($usuario, $evaluacion) && $usuario->can('evaluaciones.devolver');
    }

    /**
     * PDF para firma (vista previa o descarga): quien la ve; el evaluador
     * siempre puede imprimir la suya, los demás necesitan además la acción
     * evaluaciones.descargar.
     */
    public function descargar(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->ver($usuario, $evaluacion)
            && ($this->esEvaluador($usuario, $evaluacion) || $usuario->can('evaluaciones.descargar'));
    }

    /**
     * «Asignar evaluación» antes de que el scheduler la abra sola: RH (quien
     * autoriza) con alcance sobre el colaborador; nunca la suya propia.
     * Uso: Gate::allows('asignar', [EvaluacionPeriodoPrueba::class, $colaborador]).
     */
    public function asignar(User $usuario, Colaborador $colaborador): bool
    {
        return $usuario->can('evaluaciones.autorizar')
            && $usuario->can('evaluaciones.asignar')
            && $usuario->colaborador_id !== $colaborador->id
            && $this->alcance->alcanzaColaborador($usuario, $colaborador);
    }

    /**
     * Pedir la excepción de Dirección General (FO-RH-EPP-001): mismo
     * alcance que autorizar — RH con alcance sobre el colaborador. Resolverla
     * es de Dirección General, ver SolicitudExcepcionPeriodoPruebaPolicy.
     */
    public function solicitarExcepcion(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->autorizar($usuario, $evaluacion);
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
