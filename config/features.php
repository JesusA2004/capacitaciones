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

    'desempeno' => env('DESEMPENO_ENABLED', false),

    'nine_box' => env('NINE_BOX_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Datos de demostración
    |--------------------------------------------------------------------------
    |
    | Controla si DatabaseSeeder ejecuta DemoSeeder (cuentas @mrlana.test con
    | contraseña conocida, dashboard/solicitudes de ejemplo, gestores demo en
    | la matriz comercial). NUNCA debe estar en true en producción. Por
    | defecto se activa en local/testing y se apaga en cualquier otro entorno
    | sin importar esta bandera — ver DatabaseSeeder::run().
    |
    */
    'seed_demo_data' => env('SEED_DEMO_DATA', false),

];
