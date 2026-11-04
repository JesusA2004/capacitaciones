<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Tipo sanguíneo (Estudio socioeconómico). «No lo sé» es una respuesta válida: nunca se inventa.
 */
enum TipoSanguineo: string
{
    use OpcionesConEtiqueta;

    case OPositivo = 'o_positivo';
    case ONegativo = 'o_negativo';
    case APositivo = 'a_positivo';
    case ANegativo = 'a_negativo';
    case BPositivo = 'b_positivo';
    case BNegativo = 'b_negativo';
    case AbPositivo = 'ab_positivo';
    case AbNegativo = 'ab_negativo';
    case Desconocido = 'desconocido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::OPositivo => 'O+',
            self::ONegativo => 'O-',
            self::APositivo => 'A+',
            self::ANegativo => 'A-',
            self::BPositivo => 'B+',
            self::BNegativo => 'B-',
            self::AbPositivo => 'AB+',
            self::AbNegativo => 'AB-',
            self::Desconocido => 'No lo sé',
        };
    }
}
