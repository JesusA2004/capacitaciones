<?php

namespace App\Enums;

/**
 * Estado exterior e interior del vehículo (sección 3 de la «RESPONSIVA DE
 * VEHÍCULO»): bueno o con detalles.
 */
enum EstadoConservacion: string
{
    case Bueno = 'bueno';
    case ConDetalles = 'con_detalles';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Bueno => 'Bueno',
            self::ConDetalles => 'Con detalles',
        };
    }
}
