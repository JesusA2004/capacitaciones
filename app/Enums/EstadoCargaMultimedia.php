<?php

namespace App\Enums;

enum EstadoCargaMultimedia: string
{
    case EnProgreso = 'en_progreso';
    case Pausada = 'pausada';
    case Ensamblando = 'ensamblando';
    case Completada = 'completada';
    case Cancelada = 'cancelada';
    case Expirada = 'expirada';
    case Error = 'error';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnProgreso => 'En progreso',
            self::Pausada => 'Pausada',
            self::Ensamblando => 'Ensamblando',
            self::Completada => 'Completada',
            self::Cancelada => 'Cancelada',
            self::Expirada => 'Expirada',
            self::Error => 'Error',
        };
    }

    public function esFinal(): bool
    {
        return match ($this) {
            self::Completada, self::Cancelada, self::Expirada, self::Error => true,
            default => false,
        };
    }
}
