<?php

namespace App\Enums;

/**
 * Tipo de un material de apoyo del centro de Ayuda (config/ayuda_material.php).
 */
enum TipoMaterialAyuda: string
{
    case Video = 'video';
    case Pdf = 'pdf';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Video => 'Video',
            self::Pdf => 'Documento PDF',
        };
    }

    public function mime(): string
    {
        return match ($this) {
            self::Video => 'video/mp4',
            self::Pdf => 'application/pdf',
        };
    }
}
