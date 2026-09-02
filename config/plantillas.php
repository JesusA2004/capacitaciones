<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Plantillas y formatos precargados
    |--------------------------------------------------------------------------
    |
    | Mismo disco NAS que expedientes/reclutamiento/altas: la base de datos
    | solo guarda metadatos, el archivo real vive fuera del repositorio.
    |
    */

    'disk' => 'nas',

    'max_upload_mb' => env('PLANTILLAS_MAX_MB', 10),

    'extensiones_permitidas' => ['docx'],

];
