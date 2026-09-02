<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Almacenamiento de CVs de candidatos
    |--------------------------------------------------------------------------
    |
    | Mismo disco NAS que expedientes (config/expedientes.php) y biblioteca
    | multimedia: la base de datos solo guarda metadatos (disk/path/nombre),
    | el archivo real vive fuera del repositorio y de la base de datos.
    |
    */

    'disk' => 'nas',

    'max_upload_mb' => env('RECLUTAMIENTO_CV_MAX_MB', 10),

    'extensiones_permitidas' => ['pdf', 'doc', 'docx'],

];
