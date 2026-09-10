<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Modulo de cumpleanos
    |--------------------------------------------------------------------------
    |
    | Apaga por completo el modulo (dashboard, calendario, notificaciones
    | automaticas y command de scheduler) sin desinstalar nada. Ver
    | docs/CUMPLEANOS.md.
    |
    */
    'enabled' => (bool) env('CUMPLEANOS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Notificaciones automaticas
    |--------------------------------------------------------------------------
    */
    'notify_employee' => (bool) env('CUMPLEANOS_NOTIFY_EMPLOYEE', true),
    'notify_rh' => (bool) env('CUMPLEANOS_NOTIFY_RH', true),

    /*
    |--------------------------------------------------------------------------
    | Tarjeta de felicitacion (imagen descargable)
    |--------------------------------------------------------------------------
    |
    | Generada con GD (ver App\Services\Cumpleanos\BirthdayCardService).
    | Tamano recomendado para WhatsApp/redes internas: 1080x1350.
    |
    */
    'card_width' => (int) env('CUMPLEANOS_CARD_WIDTH', 1080),
    'card_height' => (int) env('CUMPLEANOS_CARD_HEIGHT', 1350),
    'default_background' => env('CUMPLEANOS_CARD_BACKGROUND', '#FFF8E7'),
    'show_branch' => (bool) env('CUMPLEANOS_SHOW_BRANCH', true),
    'show_employee_photo' => (bool) env('CUMPLEANOS_SHOW_PHOTO', true),
    'show_age' => (bool) env('CUMPLEANOS_SHOW_AGE', false),
    'auto_generate_cards' => (bool) env('CUMPLEANOS_AUTO_GENERATE_CARDS', true),

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento de tarjetas generadas
    |--------------------------------------------------------------------------
    |
    | Igual criterio que expedientes/reclutamiento: el archivo real vive
    | fuera del repo, la BD solo guarda la ruta logica (card_path). Nunca se
    | expone al frontend.
    |
    */
    'disk' => 'nas',

];
