<?php

namespace App\Enums;

/**
 * Estado de un lote de recibos de nómina (docs/NOMINA_QUINCENAL.md):
 * preparar o importar NUNCA publica. Solo «Emitido» significa que los
 * recibos ya son visibles para cada trabajador (web/app) y se le avisó.
 */
enum EstadoLoteNomina: string
{
    case Borrador = 'borrador';
    case Preparado = 'preparado';
    case Emitido = 'emitido';
    case Cancelado = 'cancelado';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Borrador => 'Borrador',
            self::Preparado => 'Preparado · en revisión',
            self::Emitido => 'Emitido',
            self::Cancelado => 'Cancelado',
        };
    }
}
