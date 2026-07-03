<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disco de almacenamiento multimedia
    |--------------------------------------------------------------------------
    |
    | Disco registrado en config/filesystems.php usado por MediaStorageService.
    | Nunca se expone al frontend la ruta fisica real de este disco.
    |
    */
    'disk' => 'nas',

    /*
    |--------------------------------------------------------------------------
    | Binarios de FFmpeg / FFprobe
    |--------------------------------------------------------------------------
    */
    'ffmpeg_bin' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe_bin' => env('FFPROBE_BIN', 'ffprobe'),

    /*
    |--------------------------------------------------------------------------
    | Tokens de acceso a multimedia
    |--------------------------------------------------------------------------
    */
    'token_ttl' => (int) env('MEDIA_TOKEN_TTL', 600),
    'allowed_origin' => env('MEDIA_ALLOWED_ORIGIN'),
    'max_upload_mb' => (int) env('MEDIA_MAX_UPLOAD_MB', 2048),

    /*
    |--------------------------------------------------------------------------
    | X-Accel-Redirect (Nginx)
    |--------------------------------------------------------------------------
    |
    | Cuando está activo, MediaStorageService::respuesta() no transmite el
    | archivo desde PHP: responde solo con la cabecera X-Accel-Redirect y deja
    | que Nginx sirva el archivo directamente desde el disco (ver
    | deploy/nginx/multimedia.conf y docs/PROCESAMIENTO_VIDEO.md). Debe
    | permanecer desactivado en entornos sin Nginx delante (como este WAMP de
    | desarrollo), donde el streaming directo por PHP sigue siendo correcto,
    | solo más lento bajo carga alta.
    */
    'x_accel_redirect' => (bool) env('MEDIA_X_ACCEL_REDIRECT', false),
    'x_accel_internal_prefix' => env('MEDIA_X_ACCEL_INTERNAL_PREFIX', '/protegido-nas'),

    /*
    |--------------------------------------------------------------------------
    | Carga de video por bloques reanudable (Fase 9)
    |--------------------------------------------------------------------------
    |
    | El tamaño de bloque es el que sugiere el backend al frontend al iniciar
    | una carga; el frontend puede recortarlo si el archivo es más pequeño.
    | Ver docs/PROCESAMIENTO_VIDEO.md para los límites de PHP/Nginx que deben
    | ajustarse en conjunto con estos valores.
    */
    'carga_resumible' => [
        'tamano_bloque_mb' => (int) env('MEDIA_CHUNK_SIZE_MB', 8),
        'expira_horas' => (int) env('MEDIA_CARGA_EXPIRA_HORAS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Control de avance de video
    |--------------------------------------------------------------------------
    */
    'video' => [
        'completion_percent' => (int) env('VIDEO_COMPLETION_PERCENT', 98),
        'heartbeat_seconds' => (int) env('VIDEO_HEARTBEAT_SECONDS', 8),
        'playback_rate' => (float) env('VIDEO_PLAYBACK_RATE', 1),
        // Tolerancia (segundos) para saltos hacia adelante causados por
        // variaciones normales de red/heartbeat, no por manipulacion.
        'salto_tolerancia_segundos' => 5,
        // Resoluciones candidatas para las variantes HLS. Nunca se escala
        // hacia arriba: solo se generan las <= a la resolucion original.
        'resoluciones' => [360, 480, 720, 1080],
        'segmento_segundos' => 6,
    ],

];
