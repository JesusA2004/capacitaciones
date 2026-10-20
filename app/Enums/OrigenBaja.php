<?php

namespace App\Enums;

/**
 * De dónde viene el registro de una baja:
 *
 *  - Sistema: capturada en el módulo de Bajas (solicitud del gerente o
 *    atajo de RH/jefe directo desde el ciclo laboral).
 *  - CierrePrevio: cierre laboral que ya existía antes del módulo; la
 *    migración 2026_10_20_200000 le creó su expediente de baja para que
 *    entre al tablero sin perder nada. No exige documentos ni alertas.
 *
 * La rotación de meses anteriores al módulo NO se carga baja por baja: viene
 * de los agregados mensuales del Excel de rotación (rotacion_periodos).
 */
enum OrigenBaja: string
{
    case Sistema = 'sistema';
    case CierrePrevio = 'cierre_previo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Sistema => 'Sistema',
            self::CierrePrevio => 'Cierre anterior al módulo',
        };
    }
}
