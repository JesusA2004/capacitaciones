<?php

namespace App\Enums;

enum TipoRecursoMultimedia: string
{
    case Video = 'video';
    case Documento = 'documento';
    case Imagen = 'imagen';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Video => 'Video',
            self::Documento => 'Documento',
            self::Imagen => 'Imagen',
        };
    }
}
