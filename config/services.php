<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Integraciones de sesiones en vivo (Fase 5). Deshabilitadas por defecto:
    // sin GOOGLE_MEET_ENABLED=true (o sin las credenciales de la cuenta de
    // servicio), el proveedor "google_meet" se degrada con gracia y no
    // genera ningun enlace automatico. Ver docs/SESIONES_EN_VIVO.md.
    'google_meet' => [
        'habilitado' => env('GOOGLE_MEET_ENABLED', false),
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI'),
        'service_account_path' => env('GOOGLE_SERVICE_ACCOUNT_PATH'),
        'impersonated_user' => env('GOOGLE_IMPERSONATED_USER'),
    ],

    // Igual que google_meet: sin ZOOM_ENABLED=true (o sin credenciales
    // Server-to-Server OAuth), el proveedor "zoom" se degrada con gracia.
    // ZOOM_HOST_EMAIL es el usuario de la cuenta de Zoom a nombre de quien
    // se crean las reuniones (una app Server-to-Server siempre actua como
    // un usuario concreto de la cuenta, no hay "usuario actual").
    'zoom' => [
        'habilitado' => env('ZOOM_ENABLED', false),
        'account_id' => env('ZOOM_ACCOUNT_ID'),
        'client_id' => env('ZOOM_CLIENT_ID'),
        'client_secret' => env('ZOOM_CLIENT_SECRET'),
        'webhook_secret' => env('ZOOM_WEBHOOK_SECRET'),
        'host_email' => env('ZOOM_HOST_EMAIL'),
    ],

];
