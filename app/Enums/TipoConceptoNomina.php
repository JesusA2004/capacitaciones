<?php

namespace App\Enums;

/**
 * Tipo de concepto en recibos internos de nómina y en finiquitos.
 */
enum TipoConceptoNomina: string
{
    case Percepcion = 'percepcion';
    case Deduccion = 'deduccion';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Percepcion => 'Percepción',
            self::Deduccion => 'Deducción',
        };
    }
}
