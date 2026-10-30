<?php

namespace App\Enums;

/**
 * Documentos IMSS de una incapacidad. El certificado va en cada incapacidad;
 * ST-7, ST-2 y ST-3 son del riesgo de trabajo y se guardan en la inicial.
 */
enum TipoDocumentoIncapacidad: string
{
    case Certificado = 'certificado';
    case St7 = 'st7';
    case St2 = 'st2';
    case St3 = 'st3';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Certificado => 'Certificado de incapacidad',
            self::St7 => 'ST-7 · Aviso para calificar probable riesgo de trabajo',
            self::St2 => 'ST-2 · Dictamen de alta',
            self::St3 => 'ST-3 · Dictamen de incapacidad permanente',
        };
    }

    public function esDelRiesgo(): bool
    {
        return $this !== self::Certificado;
    }
}
