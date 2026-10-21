<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vigencia por defecto del QR de incorporacion (horas)
    |--------------------------------------------------------------------------
    |
    | Usada cuando RH crea una invitacion sin especificar duracion/fecha de
    | expiracion explicita. Ver App\Services\Incorporacion\IncorporacionInvitacionService.
    |
    */
    'qr_ttl_horas' => (int) env('INCORPORACION_QR_TTL_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Base de la URL universal del QR
    |--------------------------------------------------------------------------
    |
    | El QR codifica "{qr_url_base}/{token}" (liga web universal: funciona
    | aunque el celular no tenga la app instalada). Nunca se guarda el token
    | plano en base de datos, solo aparece aqui al momento de generar/
    | regenerar la invitacion.
    |
    */
    'qr_url_base' => env('INCORPORACION_QR_URL_BASE', env('APP_URL', 'http://localhost').'/incorporacion/qr'),

    /*
    |--------------------------------------------------------------------------
    | Registro completo en la app antes de entrar
    |--------------------------------------------------------------------------
    |
    | Quien crea su cuenta con el QR captura todos los datos personales
    | contractuales (DatosFaltantesService::PERSONALES) y sube sus documentos
    | requeridos antes de entrar (App\Services\Incorporacion\RegistroAppService,
    | docs/REGISTRO_APP.md). `campos_opcionales` libera campos de esa lista
    | (por clave de columna, p. ej. 'correo_personal') sin tocar código.
    |
    */
    'registro_app' => [
        'campos_opcionales' => [],
    ],

];
