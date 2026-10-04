<?php

namespace App\Enums;

/**
 * Qué domicilio del patrón se imprime en los documentos jurídicos
 * ("…el ubicado en ____"). Se elige en Administración → Configuración →
 * Parámetros de RH (rh.domicilio_patron_documentos).
 */
enum FuenteDomicilioPatron: string
{
    case Fiscal = 'fiscal';
    case Sucursal = 'sucursal';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Fiscal => 'Domicilio fiscal de la empresa',
            self::Sucursal => 'Domicilio de la sucursal del colaborador',
        };
    }
}
