<?php

namespace App\Enums;

enum EstadoEntregaActividad: string
{
    case Entregada = 'entregada';
    case Aprobada = 'aprobada';
    case Rechazada = 'rechazada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Entregada => 'Entregada, pendiente de revisión',
            self::Aprobada => 'Aprobada',
            self::Rechazada => 'Rechazada',
        };
    }
}
