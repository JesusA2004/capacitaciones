<?php

namespace App\Enums;

/**
 * Qué baja originó el aviso a Sistemas: con el evento forma la clave única
 * (origen_tipo + origen_id + evento) que hace idempotente el envío.
 */
enum OrigenAvisoBajaSistemas: string
{
    /** Cierre laboral (baja del jefe directo / RH / módulo de Bajas; origen_id = cierre). */
    case Cierre = 'cierre';

    /**
     * Baja administrativa directa sin cierre laboral (expediente o solicitud
     * de baja aprobada fuera del cierre; origen_id = movimiento laboral de baja).
     */
    case BajaDirecta = 'baja_directa';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Cierre => 'Cierre laboral',
            self::BajaDirecta => 'Baja administrativa',
        };
    }
}
