<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Tipo de propiedad de un bien inmueble (Estudio socioeconómico).
 */
enum TipoPropiedadInmueble: string
{
    use OpcionesConEtiqueta;

    case Privada = 'privada';
    case Ejidal = 'ejidal';
    case Comunal = 'comunal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Privada => 'Privada',
            self::Ejidal => 'Ejidal',
            self::Comunal => 'Comunal',
        };
    }
}
