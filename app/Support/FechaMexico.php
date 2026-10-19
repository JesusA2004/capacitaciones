<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Fecha calendario de México (America/Mexico_City) para los campos DATE
 * legales (fecha de baja, fecha efectiva), que NO deben desfasarse un día
 * cuando la app corre en UTC (de 18:00 a 24:00 de México, now() ya cuenta el
 * día siguiente). Mismo criterio que
 * App\Services\Vacaciones\VacacionesService::hoyMexico(): se toma el día
 * calendario de México y se expresa como medianoche, para comparar días
 * completos contra columnas con cast `date`.
 */
class FechaMexico
{
    public const ZONA = 'America/Mexico_City';

    /** «Hoy» en México como string Y-m-d (columnas `date` / whereDate). */
    public static function hoy(): string
    {
        return Carbon::now(self::ZONA)->toDateString();
    }

    /** «Hoy» en México a medianoche, para comparar contra casts `date`. */
    public static function inicioDeHoy(): Carbon
    {
        return Carbon::parse(self::hoy());
    }
}
