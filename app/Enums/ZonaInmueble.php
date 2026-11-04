<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Zona del bien inmueble (Estudio socioeconómico).
 */
enum ZonaInmueble: string
{
    use OpcionesConEtiqueta;

    case Urbana = 'urbana';
    case Semiurbana = 'semiurbana';
    case Rural = 'rural';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Urbana => 'Urbana',
            self::Semiurbana => 'Semiurbana',
            self::Rural => 'Rural',
        };
    }
}
