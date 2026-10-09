<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            // Privado: nunca se sirve por URL (ni firmada). Los archivos salen
            // solo por endpoints autorizados que validan permiso y alcance.
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim((string) env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // Almacenamiento de video/documentos pesados (biblioteca multimedia).
        // En desarrollo: carpeta local (NAS_DRIVER=local, valor por defecto).
        // En produccion recomendada: carpeta del NAS montada por NFS en el
        // servidor (sigue siendo NAS_DRIVER=local, apuntando a la ruta del
        // punto de montaje via NAS_ROOT). Alternativa cuando NFS no es viable:
        // NAS_DRIVER=sftp (requiere `composer require league/flysystem-sftp-v3`).
        // Ver docs/CONFIGURACION_NAS.md para el detalle completo.
        'nas' => [
            'driver' => env('NAS_DRIVER', 'local'),
            'root' => env('NAS_ROOT', storage_path('app/private/capacitacion')),
            'host' => env('NAS_HOST'),
            'port' => (int) env('NAS_PORT', 22),
            'username' => env('NAS_USERNAME'),
            'password' => env('NAS_PASSWORD'),
            'privateKey' => env('NAS_PRIVATE_KEY'),
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
        ],

        // Árbol HISTÓRICO de expedientes (solo lectura para la migración
        // inicial, docs/MIGRACION_INICIAL_EXPEDIENTES.md). Por defecto es el
        // mismo volumen que `nas`; si el área «RH/» vive en otra carpeta
        // compartida del Synology, móntala y apunta NAS_LEGACY_ROOT ahí.
        'nas_legacy' => [
            'driver' => env('NAS_LEGACY_DRIVER', env('NAS_DRIVER', 'local')),
            'root' => env('NAS_LEGACY_ROOT', env('NAS_ROOT', storage_path('app/private/capacitacion'))),
            'host' => env('NAS_LEGACY_HOST', env('NAS_HOST')),
            'port' => (int) env('NAS_LEGACY_PORT', env('NAS_PORT', 22)),
            'username' => env('NAS_LEGACY_USERNAME', env('NAS_USERNAME')),
            'password' => env('NAS_LEGACY_PASSWORD', env('NAS_PASSWORD')),
            'privateKey' => env('NAS_LEGACY_PRIVATE_KEY', env('NAS_PRIVATE_KEY')),
            'throw' => false,
            'report' => false,
            'visibility' => 'private',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
