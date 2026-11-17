<?php

namespace App\Enums;

/**
 * Periodo de la Nine Box: un MES de Reportes (4 semanas) o un TRIMESTRE
 * (3 meses de Reportes del mismo trimestre calendario por su código
 * M-AAAA-MM). Ver docs/NINE_BOX.md.
 */
enum TipoPeriodoNineBox: string
{
    case Mes = 'mes';
    case Trimestre = 'trimestre';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Mes => 'Mes',
            self::Trimestre => 'Trimestre',
        };
    }
}
