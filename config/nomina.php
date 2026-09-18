<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de recibos de nómina
    |--------------------------------------------------------------------------
    |
    | Disco registrado en config/filesystems.php usado por
    | App\Services\Nomina\ReciboNominaService para guardar el PDF de cada
    | recibo. Nunca 'local' en producción: el VPS no es el lugar para
    | persistir documentos reales — mismo criterio que config/expedientes.php.
    |
    */
    'disk' => env('NOMINA_DISK', 'nas'),

];
