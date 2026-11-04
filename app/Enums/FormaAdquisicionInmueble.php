<?php

namespace App\Enums;

use App\Enums\Concerns\OpcionesConEtiqueta;

/**
 * Forma de adquisición del bien inmueble (Estudio socioeconómico).
 */
enum FormaAdquisicionInmueble: string
{
    use OpcionesConEtiqueta;

    case Compraventa = 'compraventa';
    case Herencia = 'herencia';
    case Legado = 'legado';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Compraventa => 'Compraventa',
            self::Herencia => 'Herencia',
            self::Legado => 'Legado',
            self::Otro => 'Otro',
        };
    }
}
