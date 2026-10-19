<?php

namespace App\Enums;

/**
 * Estado físico en que se entrega un bien mueble o un equipo electrónico
 * («ESTADO DEL MUEBLE» / «ESTADO DEL EQUIPO» del formato oficial). El vehículo
 * usa su propia valoración por exterior/interior (ver EstadoConservacion).
 */
enum EstadoFisicoResponsiva: string
{
    case Nuevo = 'nuevo';
    case BuenEstado = 'buen_estado';
    case ConDetalles = 'con_detalles';
    case Danos = 'danos';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Nuevo => 'Nuevo',
            self::BuenEstado => 'Buen estado',
            self::ConDetalles => 'Con detalles',
            self::Danos => 'Daños',
        };
    }
}
