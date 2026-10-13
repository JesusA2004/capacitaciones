<?php

namespace App\Services\DocumentosMaestros\Deteccion;

/**
 * Qué es el archivo que subió RH, por su CONTENIDO (no por la extensión):
 *
 *  - docx           paquete Word (se lee su XML; nunca OCR);
 *  - pdf_texto      PDF digital con capa de texto (posiciones sin OCR);
 *  - pdf_escaneado  PDF hecho de imágenes (solo aquí aplica el OCR local).
 */
class TipoArchivoDocumento
{
    /** Menos caracteres que esto en todo el PDF = escaneado. */
    private const MIN_CARACTERES = 25;

    public function __construct(private readonly LectorTextoPdf $lector) {}

    /**
     * @return 'docx'|'pdf_texto'|'pdf_escaneado'|null
     */
    public function detectar(string $contenido): ?string
    {
        if (str_starts_with($contenido, "PK\x03\x04") && str_contains($contenido, 'word/document.xml')) {
            return 'docx';
        }

        if (str_starts_with(ltrim(substr($contenido, 0, 1024)), '%PDF')) {
            return $this->lector->caracteresDeTexto($contenido) >= self::MIN_CARACTERES ? 'pdf_texto' : 'pdf_escaneado';
        }

        return null;
    }
}
