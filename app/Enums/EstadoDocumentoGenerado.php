<?php

namespace App\Enums;

enum EstadoDocumentoGenerado: string
{
    case Generado = 'generado';
    case Entregado = 'entregado';
    case Firmado = 'firmado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Generado => 'Generado',
            self::Entregado => 'Entregado/impreso',
            self::Firmado => 'Firmado y cargado',
        };
    }
}
