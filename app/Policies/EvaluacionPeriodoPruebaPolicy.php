<?php

namespace App\Policies;

use App\Models\Colaborador;
use App\Models\EvaluacionPeriodoPrueba;
use App\Models\User;
use App\Policies\Concerns\ConSuperAdminOperativo;
use App\Services\AlcanceOrganizacionalService;
use App\Services\Colaboradores\JerarquiaColaboradorService;

/**
 * La evaluación de periodo de prueba es una AUTOEVALUACIÓN: la contesta
 * ÚNICAMENTE el colaborador evaluado (ni RH, ni su jefe, ni super_admin).
 * RH/Dirección la asigna, ve sus respuestas, imprime el PDF y decide
 * (autorizar/devolver); el jefe directo solo le da seguimiento. Nadie
 * autoriza su propia evaluación — salvo super_admin, ver ConSuperAdminOperativo.
 */
class EvaluacionPeriodoPruebaPolicy
{
    use ConSuperAdminOperativo {
        before as private beforeSuperAdmin;
    }

    public function __construct(
        private readonly AlcanceOrganizacionalService $alcance,
        private readonly JerarquiaColaboradorService $jerarquia,
    ) {}

    /**
     * super_admin opera todo lo de RH, pero NUNCA contesta la evaluación de
     * otra persona: «contestar» lo decide solo la regla de abajo.
     */
    public function before(User $usuario, string $habilidad): ?bool
    {
        return $habilidad === 'contestar' ? null : $this->beforeSuperAdmin($usuario, $habilidad);
    }

    public function ver(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->esElEvaluado($usuario, $evaluacion)
            || $this->esJefe($usuario, $evaluacion)
            || ($usuario->can('evaluaciones.ver') && $this->alcance->alcanzaColaboradorEnModulo($usuario, $evaluacion->colaborador, 'evaluaciones'));
    }

    /** Contestar (y corregir si RH la devuelve): solo la persona evaluada. */
    public function contestar(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->esElEvaluado($usuario, $evaluacion);
    }

    public function autorizar(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $usuario->can('evaluaciones.autorizar')
            && $usuario->colaborador_id !== $evaluacion->colaborador_id
            && $this->alcance->alcanzaColaborador($usuario, $evaluacion->colaborador);
    }

    /** Devolver al colaborador para que la corrija: autorizar + la acción evaluaciones.devolver. */
    public function devolver(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->autorizar($usuario, $evaluacion) && $usuario->can('evaluaciones.devolver');
    }

    /**
     * PDF para firma (vista previa o descarga): quien la ve; su jefe directo
     * siempre puede imprimirla, RH y los demás necesitan además la acción
     * evaluaciones.descargar.
     */
    public function descargar(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $this->ver($usuario, $evaluacion)
            && ($this->esJefe($usuario, $evaluacion) || $usuario->can('evaluaciones.descargar'));
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

    private function esElEvaluado(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        return $usuario->colaborador_id !== null && $usuario->colaborador_id === $evaluacion->colaborador_id;
    }

    /**
     * Jefe directo de la persona (organigrama real) o, en evaluaciones del
     * flujo anterior, el jefe que quedó como evaluador asignado.
     */
    private function esJefe(User $usuario, EvaluacionPeriodoPrueba $evaluacion): bool
    {
        if ($usuario->colaborador_id === null || $this->esElEvaluado($usuario, $evaluacion)) {
            return false;
        }

        return $evaluacion->evaluador_colaborador_id === $usuario->colaborador_id
            || $this->jerarquia->usuarioEsJefeDe($usuario, $evaluacion->colaborador);
    }
}
