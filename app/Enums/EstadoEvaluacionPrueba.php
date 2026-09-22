<?php

namespace App\Enums;

/**
 * Evaluación de periodo de prueba: la habilita el scheduler antes del
 * vencimiento (pendiente), la captura el jefe inmediato (capturada) y la
 * autoriza RH/Dirección (autorizada) o la devuelve para recaptura (devuelta).
 */
enum EstadoEvaluacionPrueba: string
{
    case Pendiente = 'pendiente';
    case Capturada = 'capturada';
    case Devuelta = 'devuelta';
    case Autorizada = 'autorizada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente de evaluar',
            self::Capturada => 'Capturada (pendiente de autorización)',
            self::Devuelta => 'Devuelta para corrección',
            self::Autorizada => 'Autorizada',
        };
    }

    public function permiteCaptura(): bool
    {
        return $this === self::Pendiente || $this === self::Devuelta;
    }
}
