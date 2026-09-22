<?php

namespace App\Enums;

enum EstadoActa: string
{
    case Borrador = 'borrador';
    case Generada = 'generada';
    case Firmada = 'firmada';
    case Cerrada = 'cerrada';
    case Cancelada = 'cancelada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Generada => 'Formato generado',
            self::Firmada => 'Firmada',
            self::Cerrada => 'Cerrada',
            self::Cancelada => 'Cancelada',
        };
    }

    public function esEditable(): bool
    {
        return $this === self::Borrador;
    }
}
