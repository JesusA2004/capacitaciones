<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags del portal
    |--------------------------------------------------------------------------
    |
    | Controlan que partes del sistema estan visibles y accesibles. Apagar
    | una bandera NO borra nada: modelos, migraciones, controladores, rutas,
    | permisos y datos de esa fase se conservan intactos. Solo se oculta de
    | la navegacion y, si alguien entra por URL directa, se muestra una
    | pantalla "Proximamente" (o 403 en acciones de escritura).
    |
    | Ver docs/FEATURE_FLAGS.md para el detalle de que cubre cada bandera.
    |
    */

    'rh_portal' => env('RH_PORTAL_ENABLED', true),

    'capacitacion' => env('CAPACITACION_ENABLED', false),

];
