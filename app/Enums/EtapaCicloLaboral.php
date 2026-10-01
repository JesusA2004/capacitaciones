<?php

namespace App\Enums;

/**
 * Etapa del ciclo de vida laboral en la que está una persona (candidato o
 * colaborador). La deriva App\Services\CicloLaboral\CicloLaboralService a
 * partir de los datos reales — nunca se captura a mano. La Etapa 5
 * (permanencia y desarrollo) no forma parte de este recorrido: un
 * colaborador que superó su periodo de prueba queda simplemente "activo".
 */
enum EtapaCicloLaboral: string
{
    case Reclutamiento = 'reclutamiento';
    case Contratacion = 'contratacion';
    case Onboarding = 'onboarding';
    case PeriodoPrueba = 'periodo_prueba';
    case Activo = 'activo';
    case Cierre = 'cierre';
    case Reingreso = 'reingreso';
    case Finalizado = 'finalizado';
    case Descartado = 'descartado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Reclutamiento => 'Reclutamiento y selección',
            self::Contratacion => 'Contratación y expediente',
            self::Onboarding => 'Onboarding',
            self::PeriodoPrueba => 'Periodo de prueba',
            self::Activo => 'Activo',
            self::Cierre => 'Cierre laboral',
            self::Reingreso => 'Reingreso',
            self::Finalizado => 'Relación laboral concluida',
            self::Descartado => 'Proceso concluido sin contratación',
        };
    }

    /**
     * Número de etapa del modelo funcional (1–6; la 5 no se implementa en
     * este cierre). null para estados finales.
     */
    public function numero(): ?int
    {
        return match ($this) {
            self::Reclutamiento => 1,
            self::Contratacion => 2,
            self::Onboarding => 3,
            self::PeriodoPrueba => 4,
            self::Cierre, self::Reingreso => 6,
            default => null,
        };
    }
}
