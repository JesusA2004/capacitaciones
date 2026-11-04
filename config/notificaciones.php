<?php

/*
|--------------------------------------------------------------------------
| Avisos por correo a áreas internas
|--------------------------------------------------------------------------
|
| Correos operativos que PEOPLE manda a buzones de otras áreas (no a una
| cuenta de usuario). El remitente es SIEMPRE el global del .env
| (MAIL_FROM_ADDRESS / MAIL_FROM_NAME); aquí solo se configuran destinos.
|
*/

// env() convierte "true"/"false"/"(null)" en bool/null: solo un texto trae correos.
$lista = static fn (string|bool|null $valor): array => is_string($valor)
    ? array_values(array_filter(array_map(
        static fn (string $correo): string => trim($correo),
        explode(',', $valor),
    ), static fn (string $correo): bool => $correo !== ''))
    : [];

return [

    /*
    | Alta para asignar usuarios (App\Services\Notificaciones\AvisoAltaSistemasService):
    | cuando una persona entra a la fase 6 «Contratación» (RH inicia su
    | contratación y se crea su colaborador) o se autoriza su reingreso,
    | Sistemas recibe sus datos principales para darle de alta en las demás
    | plataformas. Una sola vez por contratación. Ver docs/DEPLOY.md.
    |
    | SISTEMAS_ALTA_CORREO_CC admite varias direcciones separadas por comas.
    */
    'sistemas' => [
        'activo' => (bool) env('SISTEMAS_ALTA_CORREO_ACTIVO', false),
        'para' => $lista(env('SISTEMAS_ALTA_CORREO_PARA', 'sistemas@mr-lana.com')),
        'cc' => $lista(env('SISTEMAS_ALTA_CORREO_CC', '')),
    ],

];
