<?php

namespace App\Services\Calendario;

use Illuminate\Support\Carbon;

/**
 * Días hábiles (lunes a viernes). No existe todavía un catálogo de días
 * inhábiles/festivos en el sistema — si se agrega uno en el futuro, este es
 * el único lugar que debe consultarlo (nunca repetir "sumar 15 días" suelto
 * en un Service). Usado por:
 *
 *  - EvaluacionInduccionService: 5 días hábiles para reaplicar tras
 *    reforzamiento (FO-RH-EI).
 *  - EvaluacionPeriodoPruebaService / contratos:revisar-vencimientos: 15
 *    días hábiles de aviso antes del término del periodo de prueba
 *    (FO-RH-EPP-001).
 */
class CalendarioLaboralService
{
    /**
     * Suma días hábiles a una fecha (sin contar la fecha de inicio). Sábado y
     * domingo nunca cuentan.
     */
    public function sumarDiasHabiles(Carbon|string $desde, int $diasHabiles): Carbon
    {
        $fecha = $desde instanceof Carbon ? $desde->copy() : Carbon::parse($desde);
        $restantes = $diasHabiles;

        while ($restantes > 0) {
            $fecha->addDay();

            if ($this->esHabil($fecha)) {
                $restantes--;
            }
        }

        return $fecha;
    }

    /**
     * Días hábiles completos entre dos fechas (sin contar $desde, contando
     * $hasta si es hábil). Negativo si $hasta es anterior a $desde.
     */
    public function diasHabilesEntre(Carbon|string $desde, Carbon|string $hasta): int
    {
        $inicio = $desde instanceof Carbon ? $desde->copy() : Carbon::parse($desde);
        $fin = $hasta instanceof Carbon ? $hasta->copy() : Carbon::parse($hasta);

        if ($fin->lessThan($inicio)) {
            return -$this->diasHabilesEntre($fin, $inicio);
        }

        $dias = 0;
        $cursor = $inicio->copy();

        while ($cursor->lessThan($fin)) {
            $cursor->addDay();

            if ($this->esHabil($cursor)) {
                $dias++;
            }
        }

        return $dias;
    }

    public function esHabil(Carbon $fecha): bool
    {
        return ! $fecha->isWeekend();
    }
}
