<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Fechas para leer de un vistazo y para copiar a otro sistema:
 * «15 de agosto de 1990 · 15/08/1990». Siempre en español.
 */
final class FechaTexto
{
    public static function doble(?CarbonInterface $fecha): ?string
    {
        if ($fecha === null) {
            return null;
        }

        return sprintf('%s · %s', self::larga($fecha), $fecha->format('d/m/Y'));
    }

    public static function larga(CarbonInterface $fecha): string
    {
        return $fecha->copy()->locale('es')->translatedFormat('j \d\e F \d\e Y');
    }
}
