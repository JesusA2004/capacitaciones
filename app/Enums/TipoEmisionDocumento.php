<?php

namespace App\Enums;

/**
 * Por qué existe una versión de un documento generado.
 */
enum TipoEmisionDocumento: string
{
    case Original = 'original';
    case Correccion = 'correccion';
    case Rectificacion = 'rectificacion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Original => 'Emisión original',
            self::Correccion => 'Corrección antes de firmar',
            self::Rectificacion => 'Rectificación de un documento firmado',
        };
    }
}
