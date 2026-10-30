<?php

namespace App\Enums;

/**
 * Tipo del documento de pago de una baja. La liquidación se PROPONE cuando la
 * causal está marcada como «Implica indemnización» (catálogo de causales); es
 * una clasificación con revisión de RH/Jurídico, no una resolución legal.
 */
enum TipoFiniquito: string
{
    case Finiquito = 'finiquito';
    case Liquidacion = 'liquidacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Finiquito => 'Finiquito',
            self::Liquidacion => 'Liquidación',
        };
    }
}
