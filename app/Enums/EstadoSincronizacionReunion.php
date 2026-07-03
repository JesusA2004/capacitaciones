<?php

namespace App\Enums;

enum EstadoSincronizacionReunion: string
{
    case EnProgreso = 'en_progreso';
    case Completada = 'completada';
    case Parcial = 'parcial';
    case Error = 'error';
    case Agotada = 'agotada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::EnProgreso => 'En progreso',
            self::Completada => 'Completada',
            self::Parcial => 'Completada parcialmente',
            self::Error => 'Error',
            self::Agotada => 'Reintentos agotados (revisión manual)',
        };
    }

    public function esFinal(): bool
    {
        return $this !== self::EnProgreso;
    }
}
