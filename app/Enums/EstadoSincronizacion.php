<?php

namespace App\Enums;

/**
 * Estado de sincronización de un RegistroSesion contra el proveedor externo.
 */
enum EstadoSincronizacion: string
{
    case Pendiente = 'pendiente';
    case Sincronizado = 'sincronizado';
    case Parcial = 'parcial';
    case Error = 'error';
    case Agotado = 'agotado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pendiente => 'Pendiente',
            self::Sincronizado => 'Sincronizado',
            self::Parcial => 'Sincronizado parcialmente',
            self::Error => 'Error',
            self::Agotado => 'Reintentos agotados',
        };
    }

    public function esFinal(): bool
    {
        return match ($this) {
            self::Sincronizado, self::Agotado => true,
            default => false,
        };
    }
}
