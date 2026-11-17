<?php

namespace App\Enums;

/**
 * Unidad del periodo de prueba ESPECÍFICO de un colaborador
 * (colaboradores.periodo_prueba_unidad). El del puesto siempre es en meses
 * (puestos.meses_periodo_prueba).
 */
enum UnidadPeriodoPrueba: string
{
    case Meses = 'meses';
    case Dias = 'dias';

    public function etiqueta(): string
    {
        return match ($this) {
            self::Meses => 'Meses',
            self::Dias => 'Días',
        };
    }

    /** «2 meses», «1 mes», «45 días». */
    public function describir(int $cantidad): string
    {
        return match ($this) {
            self::Meses => sprintf('%d %s', $cantidad, $cantidad === 1 ? 'mes' : 'meses'),
            self::Dias => sprintf('%d %s', $cantidad, $cantidad === 1 ? 'día' : 'días'),
        };
    }
}
