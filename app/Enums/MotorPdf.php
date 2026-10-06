<?php

namespace App\Enums;

/**
 * Motor que imprime un documento administrativo HTML a PDF
 * (App\Services\Pdf\PdfRendererFactory). Los contratos jurídicos usan su
 * propio motor DOCX / PDF overlay y nunca pasan por aquí.
 */
enum MotorPdf: string
{
    case Browsershot = 'browsershot';
    case DomPdf = 'dompdf';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Browsershot => 'Chrome (Browsershot)',
            self::DomPdf => 'DomPDF',
        };
    }

    public static function porDefecto(): self
    {
        return self::tryFrom((string) config('pdf.renderer', self::Browsershot->value)) ?? self::Browsershot;
    }
}
