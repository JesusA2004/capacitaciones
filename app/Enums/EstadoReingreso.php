<?php

namespace App\Enums;

/**
 * Reingreso de una persona que ya trabajó en la empresa: nunca se crea otro
 * colaborador, se reactiva el mismo (App\Services\CicloLaboral\ReingresoService).
 */
enum EstadoReingreso: string
{
    // Preautorización operativa pendiente (lo pidió alguien fuera de la
    // cadena de mando de la persona).
    case Solicitado = 'solicitado';
    case RevisionRh = 'revision_rh';
    case Rechazado = 'rechazado';
    // Autorizado: la persona vuelve a Etapa 2 (documentos vencidos/faltantes
    // y contratos de reingreso) y después a Etapa 3 (onboarding).
    case EnContratacion = 'en_contratacion';
    case Completado = 'completado';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Solicitado => 'Solicitado · preautorización pendiente',
            self::RevisionRh => 'En revisión de RH',
            self::Rechazado => 'No viable',
            self::EnContratacion => 'Autorizado · en contratación',
            self::Completado => 'Reingreso completado',
            self::Cancelado => 'Cancelado',
        };
    }

    public function esFinal(): bool
    {
        return in_array($this, [self::Rechazado, self::Completado, self::Cancelado], true);
    }
}
