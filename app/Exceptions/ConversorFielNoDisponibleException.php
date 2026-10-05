<?php

namespace App\Exceptions;

/**
 * 422 DOCUMENT_CONVERTER_UNAVAILABLE: no hay Microsoft Word (Windows) ni un
 * LibreOffice validado para esta versión del master. Nunca se emite un PDF
 * aproximado (PhpWord/DomPDF) como documento laboral definitivo.
 */
class ConversorFielNoDisponibleException extends DocumentoMotorException
{
    public static function para(string $documento, ?string $razon = null): self
    {
        return new self(
            'No hay un motor de conversión fiel disponible para generar este documento oficial.',
            array_filter(['documento' => $documento, 'razon' => $razon]),
        );
    }

    public function codigo(): string
    {
        return 'DOCUMENT_CONVERTER_UNAVAILABLE';
    }
}
