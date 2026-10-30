<?php

namespace App\Enums;

/** Posición de un sueldo dentro del rango de su puesto. */
enum BandaSalarial: string
{
    case BajoMinimo = 'bajo_minimo';
    case MinimoAMedio = 'minimo_a_medio';
    case MedioAMaximo = 'medio_a_maximo';
    case SobreMaximo = 'sobre_maximo';

    public function etiqueta(): string
    {
        return match ($this) {
            self::BajoMinimo => 'Bajo mínimo',
            self::MinimoAMedio => 'Mínimo a medio',
            self::MedioAMaximo => 'Medio a máximo',
            self::SobreMaximo => 'Sobre máximo',
        };
    }
}
