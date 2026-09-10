<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Push notifications via Expo
    |--------------------------------------------------------------------------
    |
    | Ver App\Services\MobilePush\ExpoPushService y App\Jobs\SendExpoPushJob.
    | Un fallo al enviar (Expo caido, token invalido) nunca debe tumbar la
    | accion principal (aprobar/rechazar/crear): el job solo registra el
    | error en el log. Ver docs/PUSH_NOTIFICATIONS.md.
    |
    */

    'enabled' => (bool) env('EXPO_PUSH_ENABLED', true),

    'endpoint' => env('EXPO_PUSH_ENDPOINT', 'https://exp.host/--/api/v2/push/send'),

];
