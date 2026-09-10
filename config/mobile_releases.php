<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Descarga directa de la app movil
    |--------------------------------------------------------------------------
    |
    | Apaga la pagina publica /app y el bloque de descarga en login sin
    | eliminar versiones ya publicadas. Ver docs/APP_RELEASES.md.
    |
    */
    'download_enabled' => (bool) env('MOBILE_APP_DOWNLOAD_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Limite de tamano por APK (MB)
    |--------------------------------------------------------------------------
    */
    'max_upload_mb' => (int) env('MOBILE_APK_MAX_MB', 250),

    /*
    |--------------------------------------------------------------------------
    | Extensiones/mimes permitidos
    |--------------------------------------------------------------------------
    |
    | Solo Android por ahora (ver seccion 9 del encargo): iOS queda
    | catalogado en el modelo/enum para cuando se habilite TestFlight/App
    | Store, pero no se acepta subida real todavia.
    |
    */
    'extensiones_permitidas' => ['apk'],
    'mimes_permitidos' => [
        'application/vnd.android.package-archive',
        'application/octet-stream',
        'application/zip',
    ],

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento
    |--------------------------------------------------------------------------
    |
    | Storage privado, nunca servido directo: siempre a traves de
    | App\Http\Controllers\AppDownloadController /
    | App\Http\Controllers\Administracion\AppReleaseController. Ver
    | App\Services\AppReleases\AppReleaseStorageService.
    |
    */
    'disk' => 'nas',

];
