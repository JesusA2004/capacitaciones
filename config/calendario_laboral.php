<?php

/*
|--------------------------------------------------------------------------
| Calendario laboral: días de descanso obligatorio (LFT art. 74)
|--------------------------------------------------------------------------
|
| Lo usa CalendarioLaboralService::sumarDiasHabilesSinFestivos() para plazos
| con efecto legal (aviso de rescisión). Es configurable porque la autoridad
| competente puede declarar días inhábiles adicionales: se agregan en
| `adicionales` (AAAA-MM-DD). La fecha calculada es apoyo administrativo y la
| valida el área competente.
|
*/

return [

    // Fechas fijas (mes-día).
    'fijos' => ['01-01', '05-01', '09-16', '12-25'],

    // Lunes móviles: [mes, número de lunes del mes].
    'lunes' => [
        [2, 1],  // Primer lunes de febrero.
        [3, 3],  // Tercer lunes de marzo.
        [11, 3], // Tercer lunes de noviembre.
    ],

    // Transmisión del Poder Ejecutivo Federal (1 de octubre, cada seis años).
    'transmision_ejecutivo' => ['anio_base' => 2024, 'cada' => 6, 'fecha' => '10-01'],

    // Días inhábiles adicionales (AAAA-MM-DD), separados por coma en el .env.
    'adicionales' => array_values(array_filter(explode(',', (string) env('CALENDARIO_DIAS_INHABILES', '')))),

];
