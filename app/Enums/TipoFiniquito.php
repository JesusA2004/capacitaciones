<?php

namespace App\Enums;

/**
 * Tipo del documento de pago de una baja. En PEOPLE no existen liquidaciones:
 * toda baja (también por bajo desempeño o por una causal grave, sustentadas con
 * actas) se cierra con FINIQUITO, nunca con indemnización ni gratificación.
 *
 * `Liquidacion` es LEGADO: solo existe para que los finiquitos calculados antes
 * de esta regla (columna `tipo_documento` casteada a este enum) se sigan
 * leyendo sin error. El sistema nunca lo produce y se muestra como «Finiquito».
 */
enum TipoFiniquito: string
{
    case Finiquito = 'finiquito';
    case Liquidacion = 'liquidacion';

    public function etiqueta(): string
    {
        return 'Finiquito';
    }
}
