<?php

namespace App\Enums;

/**
 * Tipo de archivo fuente de una versión de plantilla oficial. Define cómo
 * se normaliza a un PDF base (docs/FORMATOS_OFICIALES.md).
 */
enum TipoArchivoFormato: string
{
    case Pdf = 'pdf';
    case Docx = 'docx';
    case Imagen = 'imagen';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Docx => 'Word (DOCX)',
            self::Imagen => 'Imagen',
        };
    }
}
