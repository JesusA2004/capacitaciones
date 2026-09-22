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

    /*
    | Canales Android (los crea la app movil al pedir permiso). "default" ya
    | existe en instalaciones anteriores; "acciones" es el canal de
    | importancia alta para pendientes que piden una accion. Dejar
    | EXPO_PUSH_CANAL_ACCIONES vacio envia todo por el canal general.
    */
    'canal_general' => env('EXPO_PUSH_CANAL_GENERAL', 'default'),

    'canal_acciones' => env('EXPO_PUSH_CANAL_ACCIONES', 'acciones'),

    /*
    | POST /api/v1/dispositivos/push-prueba: push de prueba SOLO a los
    | dispositivos de la propia cuenta (nunca a un token arbitrario). Activo
    | fuera de produccion; en produccion solo con EXPO_PUSH_PRUEBA=true (QA
    | del APK) o para cuentas con app_releases.publicar (Sistemas).
    */
    'push_prueba' => (bool) env('EXPO_PUSH_PRUEBA', env('APP_ENV', 'production') !== 'production'),

];
