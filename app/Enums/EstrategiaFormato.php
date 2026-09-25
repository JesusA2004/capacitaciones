<?php

namespace App\Enums;

/**
 * Cómo se produce el PDF final de una versión de plantilla oficial:
 * - overlay: el PDF base (original, imagen convertida o Word convertido)
 *   se usa como fondo y los datos se pintan encima en las posiciones
 *   mapeadas. Preserva el documento tal cual.
 * - docx_variables: el Word trae marcadores {{variable}}; se rellenan
 *   dentro del propio DOCX (PhpWord TemplateProcessor) y el resultado se
 *   convierte a PDF.
 */
enum EstrategiaFormato: string
{
    case Overlay = 'overlay';
    case DocxVariables = 'docx_variables';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Overlay => 'Datos colocados sobre el documento',
            self::DocxVariables => 'Variables dentro del Word',
        };
    }
}
