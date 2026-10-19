<?php

namespace App\Enums;

enum TipoRecursoMultimedia: string
{
    case Video = 'video';
    // Narración de la Inducción Mr. Lana: se sirve el archivo original con
    // soporte Range, sin transcodificar ni generar HLS (no bloquea nada).
    case Audio = 'audio';
    case Documento = 'documento';
    case Imagen = 'imagen';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Video => 'Video',
            self::Audio => 'Audio',
            self::Documento => 'Documento',
            self::Imagen => 'Imagen',
        };
    }
}
