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
