<?php

namespace App\Services\Celebraciones;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Reglas de calendario de las celebraciones, en un solo lugar:
 *  - "hoy" es el día en America/Mexico_City (la app corre en UTC: a partir
 *    de las 18:00 de México, UTC ya es "mañana");
 *  - quien nació / ingresó un 29 de febrero celebra el 28 de febrero en
 *    años no bisiestos (nunca el 1 de marzo por desbordamiento de fecha);
 *  - años cumplidos = diferencia exacta de calendario, no días / 365.
 */
final class FechasCelebracion
{
    public const ZONA = 'America/Mexico_City';

    public static function hoy(): Carbon
    {
        return Carbon::parse(Carbon::now(self::ZONA)->toDateString());
    }

    /**
     * Día en que se celebra, en el año dado, una fecha original (nacimiento
     * o ingreso).
     */
    public static function ocurrenciaEn(CarbonInterface $original, int $anio): Carbon
    {
        $dia = $original->day;

        if ($original->month === 2 && $dia === 29 && ! Carbon::create($anio, 1, 1)->isLeapYear()) {
            $dia = 28;
        }

        return Carbon::create($anio, $original->month, $dia)->startOfDay();
    }

    public static function esHoy(CarbonInterface $original, ?CarbonInterface $hoy = null): bool
    {
        $hoy ??= self::hoy();

        return self::ocurrenciaEn($original, $hoy->year)->isSameDay($hoy);
    }

    /**
     * Próxima ocurrencia a partir de $desde (inclusive).
     */
    public static function proxima(CarbonInterface $original, CarbonInterface $desde): Carbon
    {
        $candidata = self::ocurrenciaEn($original, $desde->year);

        return $candidata->lt(Carbon::parse($desde->toDateString())) ? self::ocurrenciaEn($original, $desde->year + 1) : $candidata;
    }

    /**
     * Años completos entre la fecha original y $fecha (calendario exacto):
     * se cumple un año más el día de la ocurrencia de ese año (para un 29/feb
     * en año no bisiesto, el 28/feb).
     */
    public static function aniosCumplidos(CarbonInterface $original, CarbonInterface $fecha): int
    {
        $anios = $fecha->year - $original->year;

        if (Carbon::parse($fecha->toDateString())->lt(self::ocurrenciaEn($original, $fecha->year))) {
            $anios--;
        }

        return max(0, $anios);
    }

    /**
     * Limita en SQL a las personas cuyo mes (de nacimiento o ingreso) cae
     * en algún mes que toca la ventana [$desde, $hasta]. Es solo un
     * prefiltro (superconjunto): el cálculo exacto de la fecha sigue en PHP
     * con proxima()/aniosCumplidos(). Evita hidratar toda la plantilla para
     * una ventana de 30 días. whereMonth() es portable entre MariaDB y
     * SQLite. Una ventana de 11 meses o más no se filtra.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function limitarAMesesDeVentana(Builder $query, string $columna, CarbonInterface $desde, CarbonInterface $hasta): Builder
    {
        $inicio = Carbon::parse($desde->toDateString())->startOfMonth();
        $fin = Carbon::parse($hasta->toDateString())->startOfMonth();

        if ($inicio->diffInMonths($fin) >= 11) {
            return $query;
        }

        $meses = [];
        for ($mes = $inicio->copy(); $mes->lte($fin); $mes->addMonth()) {
            $meses[$mes->month] = true;
        }

        return $query->where(function (Builder $sub) use ($columna, $meses): void {
            foreach (array_keys($meses) as $mes) {
                $sub->orWhereMonth($columna, $mes);
            }
        });
    }

    public static function textoAnios(int $anios): string
    {
        return sprintf('%d %s', $anios, $anios === 1 ? 'año' : 'años');
    }
}
