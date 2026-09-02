<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Alta digital de colaborador
    |--------------------------------------------------------------------------
    |
    | Mismo disco NAS que expedientes/reclutamiento: la base de datos solo
    | guarda metadatos (disk/path/nombre), el archivo real vive fuera del
    | repositorio y de la base de datos.
    |
    */

    'disk' => 'nas',

    'max_upload_mb' => env('ALTAS_MAX_UPLOAD_MB', 15),

    'extensiones_documentos' => ['pdf', 'jpg', 'jpeg', 'png'],

    'extensiones_foto' => ['jpg', 'jpeg', 'png'],

    // Dias de vigencia de la liga publica antes de expirar.
    'token_dias_vigencia' => env('ALTAS_TOKEN_DIAS', 7),

];
