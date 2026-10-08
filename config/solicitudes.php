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
            'documento_expediente_clave' => 'formato_vacaciones',
        ],
        // Permisos (formato_permiso) y préstamos (contrato, pagaré, carta de
        // retención) ya NO se generan aquí: los produce el motor de
        // documentos maestros sobre el PDF original de MR. LANA, en la
        // tarjeta "Formato de permiso" / "Documentos del préstamo" del
        // propio trámite (config/documentos_maestros.php).
        TipoSolicitudInterna::BajaColaborador->value => [
            'slug' => 'formato-baja-personal',
            'generar_en' => 'aprobacion',
            'requiere_firma' => true,
            'documento_expediente_clave' => 'documento_baja',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Visto bueno del jefe inmediato
    |--------------------------------------------------------------------------
    |
    | Tipos de solicitud que, antes de la autorización final de RH/Dirección,
    | requieren el visto bueno del jefe inmediato (o gerente) del colaborador
    | según la estructura jerárquica real (colaboradores.jefe_id/gerente_id).
    | Ver App\Services\Solicitudes\AprobacionJerarquicaService.
    |
    */

    // Orden: gerente de su sucursal → regional de su región → RH autoriza
    // (App\Services\Solicitudes\AprobacionJerarquicaService). Constancias,
    // actualizaciones de datos, incapacidades y bajas van directo a RH.
    'visto_bueno_jefe' => [
        TipoSolicitudInterna::Permiso->value,
        TipoSolicitudInterna::Vacaciones->value,
        TipoSolicitudInterna::PermisoConGoce->value,
        TipoSolicitudInterna::PermisoSinGoce->value,
        TipoSolicitudInterna::PermisoTiempo->value,
        TipoSolicitudInterna::SalidaTemprano->value,
        TipoSolicitudInterna::LlegadaTarde->value,
        TipoSolicitudInterna::PrestamoInterno->value,
        TipoSolicitudInterna::PermisoEspecialCumpleanos->value,
        TipoSolicitudInterna::PermisoEspecialPaternidad->value,
        TipoSolicitudInterna::PermisoEspecialFallecimiento->value,
    ],

];
