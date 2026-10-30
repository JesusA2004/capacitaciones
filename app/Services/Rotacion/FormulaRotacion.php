<?php

namespace App\Services\Rotacion;

/**
 * Definición única de la rotación (PDF Etapa 1, pág. 17, R2), la misma en
 * Tablero, Bajas, histórico del Excel y exportaciones:
 *
 *   Rotación = bajas del periodo ÷ plantilla promedio × 100
 *   Plantilla promedio = (plantilla al inicio + plantilla al cierre) ÷ 2
 *
 * Sin plantilla el índice es null («sin datos»): nunca 0 ni división entre cero.
 */
final class FormulaRotacion
{
    public static function plantillaPromedio(int|float $inicio, int|float $cierre): float
    {
        return round(($inicio + $cierre) / 2, 2);
    }

    public static function indice(?int $bajas, ?float $plantillaPromedio): ?float
    {
        return $bajas !== null && $plantillaPromedio !== null && $plantillaPromedio > 0
            ? round($bajas * 100 / $plantillaPromedio, 2)
            : null;
    }
}
