<?php

namespace App\Enums;

/**
 * Avance de la Certificación Mr. Lana por formato oficial (RH-CC-R-001):
 * sin formato → formato descargado (se llena y firma a pluma) → certificado
 * (formato firmado subido y certificado emitido).
 */
enum EstadoCertificacionMrLana: string
{
    case SinFormato = 'sin_formato';
    case FormatoDescargado = 'formato_descargado';
    case Certificado = 'certificado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::SinFormato => 'Sin formato',
            self::FormatoDescargado => 'Formato descargado',
            self::Certificado => 'Certificado',
        };
    }
}
