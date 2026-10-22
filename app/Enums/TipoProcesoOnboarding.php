<?php

namespace App\Enums;

/**
 * De dónde nace un OnboardingProceso:
 *
 *  - Ingreso: el onboarding de siempre (Etapa 3), lo abre la firma de los
 *    contratos (AltaColaboradorService → OnboardingService::iniciar()). Al
 *    completarse ACTIVA al colaborador (empieza a laborar).
 *  - Reinduccion: RH le vuelve a asignar la Inducción Mr. Lana a alguien que
 *    ya no tiene un onboarding de ingreso abierto (p. ej. personal activo).
 *    Solo lleva el módulo institucional con evaluación formal y al cerrarse
 *    no activa a nadie ni toca su contratación ni su ciclo laboral.
 *
 * Ver App\Services\Onboarding\AsignacionInduccionService.
 */
enum TipoProcesoOnboarding: string
{
    case Ingreso = 'ingreso';
    case Reinduccion = 'reinduccion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ingreso => 'Onboarding de ingreso',
            self::Reinduccion => 'Reinducción',
        };
    }
}
