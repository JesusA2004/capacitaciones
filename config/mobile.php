<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Configuracion remota de la app movil (GET /api/v1/app/config)
    |--------------------------------------------------------------------------
    |
    | Controla version minima/mas reciente, mantenimiento y feature flags
    | propios de la app, sin necesidad de publicar una version nueva para
    | cambiar el mensaje o forzar actualizacion. Ver docs/API_MOVIL.md.
    |
    */

    // Conservados por compatibilidad con clientes ya en campo que todavia
    // leen estas claves "planas" (pre version-por-plataforma). No eliminar.
    'minimum_version' => env('APP_MOBILE_MIN_VERSION', '1.0.0'),
    'latest_version' => env('APP_MOBILE_LATEST_VERSION', '1.0.0'),
    'force_update' => (bool) env('APP_MOBILE_FORCE_UPDATE', false),

    'maintenance' => (bool) env('APP_MOBILE_MAINTENANCE', false),

    'maintenance_message' => env('APP_MOBILE_MAINTENANCE_MESSAGE'),

    /*
    |--------------------------------------------------------------------------
    | Version minima por plataforma
    |--------------------------------------------------------------------------
    |
    | La app compara su propia version/build contra estos valores para
    | decidir si debe forzar actualizacion. iOS queda null hasta que exista
    | distribucion real (TestFlight/App Store) — ver
    | App\Services\AppReleases\AppReleaseService::latestPublicada() para la
    | version realmente descargable.
    |
    */
    'minimum_android_version' => env('APP_MOBILE_MIN_ANDROID_VERSION', env('APP_MOBILE_MIN_VERSION', '1.0.0')),
    'minimum_android_build' => env('APP_MOBILE_MIN_ANDROID_BUILD'),
    'minimum_ios_version' => env('APP_MOBILE_MIN_IOS_VERSION'),
    'minimum_ios_build' => env('APP_MOBILE_MIN_IOS_BUILD'),

    /*
    |--------------------------------------------------------------------------
    | Features de la app (independientes de config/features.php, que es del
    | portal web)
    |--------------------------------------------------------------------------
    */
    'features' => [
        'push' => (bool) env('EXPO_PUSH_ENABLED', true),
        'rh_mobile' => (bool) env('APP_MOBILE_RH_ENABLED', true),
        'biometrics' => (bool) env('APP_MOBILE_BIOMETRICS_ENABLED', true),
        'qr_onboarding' => (bool) env('APP_MOBILE_QR_ONBOARDING_ENABLED', true),
        'cumpleanos' => (bool) env('CUMPLEANOS_ENABLED', true),
    ],

];
