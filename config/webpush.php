<?php

/*
|--------------------------------------------------------------------------
| Web Push (notificaciones del navegador con la pestaña cerrada)
|--------------------------------------------------------------------------
|
| Todo lo que llega a la app móvil por Expo también llega al navegador
| donde el usuario activó las notificaciones de escritorio (PushNotifier
| replica el envío en App\Services\WebPush\WebPushNotifier). Ver
| docs/PUSH_NOTIFICATIONS.md.
|
| Las llaves VAPID identifican al servidor ante el servicio push del
| navegador. Si no se definen en el .env, se generan UNA vez y se guardan en
| storage/app/private/webpush/vapid.json (deben quedarse fijas: cambiarlas
| invalida todas las suscripciones existentes). Para fijarlas a mano:
| `php artisan webpush:claves`.
|
| Requiere HTTPS en producción (localhost está exento).
*/

return [

    'enabled' => (bool) env('WEBPUSH_ENABLED', true),

    'vapid' => [
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'sistemas@mr-lana.com')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    // Segundos que el servicio push conserva un aviso si el equipo está
    // apagado (después se descarta: un aviso de hace días ya no sirve).
    'ttl' => (int) env('WEBPUSH_TTL', 60 * 60 * 24),

    'icono' => '/apple-touch-icon.png',
    'insignia' => '/favicon-32x32.png',

];
