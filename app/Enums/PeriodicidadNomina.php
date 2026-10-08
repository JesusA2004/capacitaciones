<?php

namespace App\Enums;

/**
 * Periodicidad de pago del recibo administrativo (no CFDI):
 *  - semanal: lunes → domingo;
 *  - quincenal: 1 → 15 y 16 → último día REAL del mes (28/29/30/31).
 */
enum PeriodicidadNomina: string
{
    case Semanal = 'semanal';
    case Quincenal = 'quincenal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Semanal => 'Semanal',
            self::Quincenal => 'Quincenal',
        };
    }

    /** Días que paga el periodo completo (base del sueldo diario). */
    public function diasBase(): int
    {
        return match ($this) {
            self::Semanal => 7,
            self::Quincenal => 15,
        };
    }
}
