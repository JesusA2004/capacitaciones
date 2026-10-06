<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tabla legal de dias de vacaciones (Mexico, LFT), configurable
    |--------------------------------------------------------------------------
    |
    | Clave = anios completos de antiguedad, valor = dias generados para ese
    | aniversario. A partir del ultimo anio de la tabla, se suma
    | 'incremento_por_bloque' cada 'anios_por_bloque' anios adicionales.
    |
    */

    'tabla_dias' => [
        1 => 12,
        2 => 14,
        3 => 16,
        4 => 18,
        5 => 20,
    ],

    'incremento_por_bloque' => 2,

    'anios_por_bloque' => 5,

    /*
    |--------------------------------------------------------------------------
    | Días que NO cuentan como vacaciones (regla MR. LANA)
    |--------------------------------------------------------------------------
    |
    | 0 = domingo … 6 = sábado (Carbon::dayOfWeek). El domingo no se puede
    | elegir ni descuenta saldo; el sábado sí cuenta. Ver
    | App\Services\Solicitudes\FechasSolicitudService.
    |
    */

    'dias_no_computables' => [0],

];
