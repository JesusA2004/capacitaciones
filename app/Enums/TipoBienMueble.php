<?php

namespace App\Enums;

/**
 * Catálogo de mobiliario de la «RESPONSIVA DE BIEN MUEBLE» (sección 2,
 * «IDENTIFICACIÓN DEL MOBILIARIO»). «Otro» habilita una descripción libre.
 */
enum TipoBienMueble: string
{
    case Escritorio = 'escritorio';
    case Silla = 'silla';
    case Mesa = 'mesa';
    case Sillon = 'sillon';
    case Archivero = 'archivero';
    case Librero = 'librero';
    case Gabinete = 'gabinete';
    case Otro = 'otro';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Escritorio => 'Escritorio',
            self::Silla => 'Silla',
            self::Mesa => 'Mesa',
            self::Sillon => 'Sillón',
            self::Archivero => 'Archivero',
            self::Librero => 'Librero',
            self::Gabinete => 'Gabinete',
            self::Otro => 'Otro',
        };
    }
}
