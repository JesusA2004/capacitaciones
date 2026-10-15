<?php

namespace App\Enums;

/**
 * Cómo se obtuvo el texto de una página del PDF histórico al dividirlo.
 */
enum MetodoLecturaPagina: string
{
    case Texto = 'texto';
    case Ocr = 'ocr';
    case SinTexto = 'sin_texto';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Texto => 'Texto del PDF',
            self::Ocr => 'OCR',
            self::SinTexto => 'Página escaneada sin OCR disponible',
        };
    }
}
