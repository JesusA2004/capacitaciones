<?php

namespace App\Enums;

/**
 * Qué tan fiel es el PDF de un documento Word respecto al original.
 *
 *  - Nativa:     Microsoft Word abrió el DOCX y exportó el PDF (mismo motor
 *                de composición con el que Jurídico diseñó el documento).
 *  - Alta:       LibreOffice headless; solo cuenta como definitiva si ESA
 *                versión del master pasó el QA visual con LibreOffice.
 *  - Aproximada: PhpWord + DomPDF; reconstruye el documento y NO conserva el
 *                diseño. Solo vista previa/desarrollo, nunca un documento
 *                laboral definitivo.
 */
enum FidelidadConversion: string
{
    case Nativa = 'nativa';
    case Alta = 'alta';
    case Aproximada = 'aproximada';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Nativa => 'Nativa (Microsoft Word)',
            self::Alta => 'Alta (LibreOffice validado)',
            self::Aproximada => 'Aproximada (solo vista previa)',
        };
    }

    public function permiteDefinitivo(): bool
    {
        return $this !== self::Aproximada;
    }

    public static function deConversor(string $conversor): self
    {
        return match ($conversor) {
            'word' => self::Nativa,
            'libreoffice' => self::Alta,
            default => self::Aproximada,
        };
    }
}
