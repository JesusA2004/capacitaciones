<?php

namespace App\Enums;

/** Estado físico en que se entrega cada equipo de una responsiva («Estado» del formato). */
enum CondicionActivoResponsiva: string
{
    case Nuevo = 'nuevo';
    case BuenEstado = 'buen_estado';
    case Usado = 'usado';
    case ConDetalles = 'con_detalles';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Nuevo => 'Nuevo',
            self::BuenEstado => 'Usado en buen estado',
            self::Usado => 'Usado',
            self::ConDetalles => 'Con detalles (ver observaciones)',
        };
    }
}
