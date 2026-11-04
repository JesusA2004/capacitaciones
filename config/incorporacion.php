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

        // «Fotografía para expediente» (= foto de perfil, FotoColaboradorService)
        // obligatoria para cerrar el registro (docs/FOTO_EXPEDIENTE.md). Solo
        // se apaga si una versión de la app sin el paso «foto» sigue en uso.
        'foto_obligatoria' => (bool) env('REGISTRO_APP_FOTO_OBLIGATORIA', true),

        // Tipo documental que la foto de perfil cubre en el registro digital
        // («2 fotografías tamaño infantil»): no se pide subirlo aparte.
        'documento_cubierto_por_foto' => 'fotografia',

        // Solicitud de empleo + Estudio socioeconómico en el registro
        // (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md). «cliente»: finalizar()
        // los exige solo a la app que declara implementarlos (cabecera
        // X-People-Solicitud-Empleo: 1); las versiones instaladas siguen
        // cerrando su registro y RH completa lo faltante en la web.
        // «siempre»: se exige a todos. «nunca»: no se exige en el registro.
        // En cualquier caso, el contrato no se genera sin ambos documentos.
        'exigir_solicitud_empleo' => env('INCORPORACION_REGISTRO_EXIGE_SOLICITUD', 'cliente'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Documentos de ingreso antes del contrato
    |--------------------------------------------------------------------------
    |
    | La Solicitud de empleo y el Estudio socioeconómico deben estar completos
    | y generados antes del paquete de contratación (App\Services\
    | Incorporacion\SolicitudEmpleo\RequisitoDocumentosIngresoService). No es
    | retroactivo: solo personas en incorporación sin contrato generado.
    |
    */
    'documentos_ingreso' => [
        'exigir_antes_de_contrato' => (bool) env('INCORPORACION_DOCUMENTOS_INGRESO_ANTES_DE_CONTRATO', true),
    ],

];
