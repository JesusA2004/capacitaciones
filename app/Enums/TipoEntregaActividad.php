<?php

namespace App\Enums;

enum TipoEntregaActividad: string
{
    case Archivo = 'archivo';
    case Texto = 'texto';
    case Enlace = 'enlace';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Archivo => 'Archivo',
            self::Texto => 'Texto',
            self::Enlace => 'Enlace',
        };
    }
}
