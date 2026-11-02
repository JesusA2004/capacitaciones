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
    // Origen (dominio) autorizado para pedir multimedia desde un frontend
    // servido en otro host (CORS). Por defecto el dominio real del portal;
    // deploy/nginx/multimedia.conf debe usar este mismo valor en su cabecera
    // Access-Control-Allow-Origin. Nunca "*" para contenido privado.
    'allowed_origin' => env('MEDIA_ALLOWED_ORIGIN', 'https://people.mr-lana.com'),
    'max_upload_mb' => (int) env('MEDIA_MAX_UPLOAD_MB', 2048),
    // Audio de narración de la inducción: se sube en UN solo request, así que
    // debe caber en client_max_body_size (Nginx) y post_max_size (PHP), que en
    // producción son 300M (docs/DEPLOY.md). El video va por bloques.
    'audio_max_mb' => (int) env('MEDIA_AUDIO_MAX_MB', 200),

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
    | Cola dedicada del procesamiento de video (ProcesarVideoJob)
    |--------------------------------------------------------------------------
    |
    | La conversión a HLS tarda minutos u horas: va a su PROPIA conexión/cola
    | para no quedar atrás de los PDF ni re-ejecutarse a la mitad. La conexión
    | «media» (config/queue.php) usa la tabla `jobs` con retry_after mayor que
    | el timeout del job (3600 s); su worker es
    | deploy/supervisor/people-media.conf (`queue:work media --queue=media`).
    | Con QUEUE_CONNECTION=sync (pruebas) se procesa en línea, como siempre.
    | Ver docs/PROCESAMIENTO_VIDEO.md §6.
    */
    'cola' => [
        'conexion' => env('MEDIA_QUEUE_CONNECTION', env('QUEUE_CONNECTION', 'database') === 'sync' ? 'sync' : 'media'),
        'nombre' => env('MEDIA_QUEUE', 'media'),
        // Minutos sin que un video salga de «pendiente»/«procesando» antes de
        // avisar a RH que la cola de procesamiento podría no estar corriendo.
        'alerta_minutos' => (int) env('MEDIA_QUEUE_ALERTA_MINUTOS', 10),
        // Minutos tras los que un «procesando» se considera atorado (el job
        // dura como máximo 60 min; un worker caído lo deja a medias).
        'procesando_atorado_minutos' => (int) env('MEDIA_PROCESANDO_ATORADO_MINUTOS', 75),
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
        // 480 + 720 por defecto: cubre celular y escritorio y codifica mucho
        // más rápido que 4 variantes (una explicación no necesita 1080p).
        'resoluciones' => array_values(array_filter(array_map('intval', explode(',', (string) env('VIDEO_RESOLUCIONES', '480,720'))))),
        // Velocidad/calidad de x264 (una sola pasada para todas las variantes,
        // ver FfmpegService::convertirAHls): «veryfast» + CRF 23 por defecto.
        'preset' => env('VIDEO_PRESET', 'veryfast'),
        'crf' => (int) env('VIDEO_CRF', 23),
        'segmento_segundos' => 6,
    ],

];
