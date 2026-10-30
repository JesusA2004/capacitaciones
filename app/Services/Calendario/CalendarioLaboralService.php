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

    /**
     * Igual que sumarDiasHabiles() pero sin contar los días de descanso
     * obligatorio (config/calendario_laboral.php). Para plazos con efecto
     * legal como el aviso de rescisión; los plazos internos anteriores
     * conservan su cómputo de lunes a viernes.
     */
    public function sumarDiasHabilesSinFestivos(Carbon|string $desde, int $diasHabiles): Carbon
    {
        $fecha = $desde instanceof Carbon ? $desde->copy() : Carbon::parse($desde);
        $restantes = $diasHabiles;

        while ($restantes > 0) {
            $fecha->addDay();

            if ($this->esHabil($fecha) && ! $this->esFestivo($fecha)) {
                $restantes--;
            }
        }

        return $fecha;
    }

    public function esFestivo(Carbon $fecha): bool
    {
        if (in_array($fecha->toDateString(), array_map('strval', (array) config('calendario_laboral.adicionales', [])), true)) {
            return true;
        }

        if (in_array($fecha->format('m-d'), array_map('strval', (array) config('calendario_laboral.fijos', [])), true)) {
            return true;
        }

        $transmision = (array) config('calendario_laboral.transmision_ejecutivo', []);

        if (isset($transmision['anio_base'], $transmision['cada'], $transmision['fecha'])
            && $fecha->format('m-d') === (string) $transmision['fecha']
            && ($fecha->year - (int) $transmision['anio_base']) % (int) $transmision['cada'] === 0) {
            return true;
        }

        foreach ((array) config('calendario_laboral.lunes', []) as $regla) {
            if ($fecha->isMonday() && $fecha->month === (int) $regla[0] && (int) ceil($fecha->day / 7) === (int) $regla[1]) {
                return true;
            }
        }

        return false;
    }
}
