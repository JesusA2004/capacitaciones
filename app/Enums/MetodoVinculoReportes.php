<?php

namespace App\Enums;

/**
 * Cómo se creó un vínculo PEOPLE ↔ Reportes. Ninguno es automático: el
 * número de empleado de Reportes (clave NOI) NO es único (dos personas
 * distintas comparten la clave «7»), así que todo vínculo lo confirma una
 * persona con el permiso seguimiento.vincular (docs/SEGUIMIENTO.md).
 */
enum MetodoVinculoReportes: string
{
    /** Elegido a mano en «Vínculos con Reportes». */
    case Manual = 'manual';

    /** PEOPLE lo sugirió (nombre idéntico) y una persona lo confirmó. */
    case SugerenciaConfirmada = 'sugerencia_confirmada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Manual => 'Elegido a mano',
            self::SugerenciaConfirmada => 'Sugerencia confirmada',
        };
    }
}
