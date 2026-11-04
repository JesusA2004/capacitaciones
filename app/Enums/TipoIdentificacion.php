<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Identificación oficial que presenta la persona (Estudio socioeconómico).
 */
enum TipoIdentificacion: string
{
    use OpcionesConEtiqueta;

    case Ine = 'ine';
    case Licencia = 'licencia';
    case Cedula = 'cedula';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Ine => 'INE / IFE',
            self::Licencia => 'Licencia de conducir',
            self::Cedula => 'Cédula profesional',
            self::Otro => 'Otra',
        };
    }
}
