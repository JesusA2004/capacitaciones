<?php

use App\Enums\TipoSolicitudInterna;

return [

    /*
    |--------------------------------------------------------------------------
    | Formato oficial por tipo de solicitud
    |--------------------------------------------------------------------------
    |
    | Mapa tipo de solicitud (App\Enums\TipoSolicitudInterna) -> formato
    | oficial fijo que le corresponde (ver docs/FORMATOS_OFICIALES.md y
    | App\Services\Formatos\OfficialFormatOverlayService). Un tipo sin
    | entrada aquí no genera ningún formato oficial automáticamente.
    |
    | - slug: coincide con OfficialFormat::slug (sembrado por
    |   php artisan formatos:importar-originales, ver
    |   App\Console\Commands\ImportarFormatosOriginalesCommand).
    | - generar_en: 'creacion' | 'aprobacion' | 'cierre' — en qué transición
    |   del flujo de la solicitud se genera el PDF automáticamente.
    | - requiere_firma: si el flujo espera que RH suba de vuelta el PDF
    |   firmado (ver SolicitudInterna::documentosGenerados()).
    |
    */

    'formatos' => [
        TipoSolicitudInterna::Vacaciones->value => [
            'slug' => 'formato-vacaciones',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::PermisoConGoce->value => [
            'slug' => 'formato-permiso',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::PermisoSinGoce->value => [
            'slug' => 'formato-permiso',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::PermisoTiempo->value => [
            'slug' => 'formato-permiso',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::SalidaTemprano->value => [
            'slug' => 'formato-permiso',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::LlegadaTarde->value => [
            'slug' => 'formato-permiso',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::PrestamoInterno->value => [
            'slug' => 'contrato-credito-colaboradores',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
        TipoSolicitudInterna::BajaColaborador->value => [
            'slug' => 'formato-baja-personal',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
        ],
    ],

];
