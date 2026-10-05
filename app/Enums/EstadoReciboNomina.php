<?php

namespace App\Enums;

/**
 * Estado de un recibo de nómina: el borrador solo lo ve RH (se puede
 * ajustar); al emitirse se genera el PDF y lo ve el colaborador.
 */
enum EstadoReciboNomina: string
{
    case Borrador = 'borrador';
    case Emitido = 'emitido';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Emitido => 'Emitido',
        };
    }
}
