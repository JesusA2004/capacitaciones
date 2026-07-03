<?php

namespace App\Enums;

enum EstadoAsignacion: string
{
    case Pendiente = 'pendiente';
    case EnProgreso = 'en_progreso';
    case Completada = 'completada';
    case Vencida = 'vencida';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::EnProgreso => 'En progreso',
            self::Completada => 'Completada',
            self::Vencida => 'Vencida',
            self::Cancelada => 'Cancelada',
        };
    }
}
