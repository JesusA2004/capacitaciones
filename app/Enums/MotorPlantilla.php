<?php

namespace App\Enums;

/**
 * Cómo se renderiza una plantilla documental (App\Models\DocumentTemplate):
 * - html: cuerpo HTML con {{variables}} capturado por RH, convertido a PDF con DomPDF.
 * - docx: archivo Word con {{variables}} (PhpWord), convertido a PDF.
 * - pdf_overlay: formato oficial PDF fijo con datos encima (App\Models\OfficialFormat).
 *
 * El texto jurídico siempre lo aporta RH/Jurídico (archivo o cuerpo HTML);
 * el sistema solo sustituye variables.
 */
enum MotorPlantilla: string
{
    case Html = 'html';
    case Docx = 'docx';
    case PdfOverlay = 'pdf_overlay';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Html => 'HTML con variables',
            self::Docx => 'Word (DOCX) con variables',
            self::PdfOverlay => 'Formato oficial PDF (overlay)',
        };
    }
}
