<?php

namespace App\Enums;

/**
 * Resultado de regularizar los contratos de UN colaborador (individual o
 * dentro de un lote de «Generar todos»).
 */
enum ResultadoGeneracionContratos: string
{
    case Generado = 'generado';
    case SinCambios = 'sin_cambios';
    case Omitido = 'omitido';
    case Error = 'error';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Generado => 'Generado',
            self::SinCambios => 'Ya tenía sus contratos',
            self::Omitido => 'Omitido',
            self::Error => 'Error',
        };
    }
}
