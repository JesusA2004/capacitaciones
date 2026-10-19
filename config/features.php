<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags del portal
    |--------------------------------------------------------------------------
    |
    | Controlan que partes del sistema estan visibles y accesibles. Apagar
    | una bandera NO borra nada: modelos, migraciones, controladores, rutas,
    | permisos y datos de esa fase se conservan intactos. Solo se oculta de
    | la navegacion y, si alguien entra por URL directa, se muestra una
    | pantalla "Proximamente" (o 403 en acciones de escritura).
    |
    | Ver docs/FEATURE_FLAGS.md para el detalle de que cubre cada bandera.
    |
    */

    'rh_portal' => env('RH_PORTAL_ENABLED', true),

    'capacitacion' => env('CAPACITACION_ENABLED', false),

    /*
    | Flujo documental ANTERIOR (envío a corporativo → recepción → escaneo).
    | Apagado por decisión de negocio 2026: el documento que se imprime y
    | firma en papel CONCLUYE al registrar su firma física; el original se
    | resguarda en papel y ya no se envía, recibe ni escanea. Con false, esos
    | pasos solo pueden ejecutarse sobre expedientes que YA venían en el tramo
    | legacy (para poder cerrarlos); sobre documentos nuevos se rechazan.
    | Ver App\Services\DocumentosLaborales\FlujoDocumentalService.
    */
    'documentos_flujo_legacy' => (bool) env('DOCUMENTOS_FLUJO_LEGACY', false),

    /*
    | Flujo ANTERIOR del finiquito del cierre laboral: autorizar finiquito →
    | regional programa el pago → gerente agenda cita → firma/huella →
    | confirmar pago. Apagado por decisión de negocio 2026: hoy RH calcula y
    | ajusta el finiquito y REGISTRA SU ENTREGA (registrarEntregaFiniquito),
    | que lo congela y cierra el cierre — también los cierres que venían del
    | tramo anterior (ninguno se queda atorado). Con false, los pasos de ese
    | flujo (CierreLaboralService::autorizarFiniquito / programarPago /
    | registrarCita / registrarFiniquitoFirmado / confirmarPago) se rechazan;
    | solo se habilitan para reproducir/cerrar expedientes legacy en pruebas y
    | datos de demostración. Ver App\Services\CierreLaboral\CierreLaboralService.
    */
    'cierre_flujo_legacy' => (bool) env('CIERRE_FLUJO_LEGACY', false),

    /*
    | Flujo ANTERIOR de la Inducción Mr. Lana: la «sesión en vivo» que RH
    | presentaba (Meet/presencial) y al terminar «enviaba la evaluación»
    | (App\Services\Onboarding\SesionInduccionService). Apagado por decisión de
    | negocio 2026: hoy la inducción se ASIGNA al colaborador como aviso y la ve
    | solo (hojas del PDF + material de audio/video) antes de su evaluación. Con
    | false, las rutas de sesiones de inducción (web y API) se bloquean
    | (403/Proximamente); los modelos, tablas, Service y el HISTORIAL de sesiones
    | ya presentadas se CONSERVAN intactos (auditables). No borra datos. Ver
    | docs/INDUCCION_NUEVO_FLUJO.md.
    */
    'induccion_sesiones_legacy' => (bool) env('INDUCCION_SESIONES_LEGACY', false),

    /*
    | Recibos de nómina en autoservicio (Mi espacio web y app). Apagado por
    | decisión de negocio: el colaborador recibe su recibo IMPRESO (RH genera
    | el PDF). Con false el colaborador no ve, no descarga ni recibe aviso de
    | sus recibos — ni en "Mis recibos" ni como documento de su expediente.
    | RH (nomina.recibos.ver / documentos_laborales.ver) los sigue viendo.
    */
    'recibos_colaborador' => env('RECIBOS_COLABORADOR_ENABLED', false),

    /*
    | Modulos de la etapa 6 (Desarrollo) que todavia NO tienen pantalla real.
    | Apagados por defecto: el sidebar no muestra accesos falsos (ver
    | docs/ROLES_Y_NAVEGACION.md y docs/FEATURE_FLAGS.md). Al construir cada
    | modulo se cambia el href marcador por su ruta y se enciende la bandera.
    */
    'evaluacion_desempeno' => (bool) env('EVALUACION_DESEMPENO_ENABLED', false),

    'nine_box' => (bool) env('NINE_BOX_ENABLED', false),

    'certificacion' => (bool) env('CERTIFICACION_ENABLED', false),

    /*
    | Notificaciones en tiempo real del portal web (Laravel Reverb). Debe
    | coincidir con VITE_REVERB_ENABLED (lo lee el navegador al compilar).
    | Sin Reverb corriendo: false (la campana usa polling).
    */
    'realtime_web' => (bool) env('VITE_REVERB_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Datos de demostración
    |--------------------------------------------------------------------------
    |
    | Controla si DatabaseSeeder ejecuta DemoSeeder (cuentas @mrlana.test con
    | contraseña conocida, dashboard/solicitudes de ejemplo, gestores demo en
    | la matriz comercial). NUNCA debe estar en true en producción. Por
    | defecto se activa en local/testing y se apaga en cualquier otro entorno
    | sin importar esta bandera — ver DatabaseSeeder::run().
    |
    */
    'seed_demo_data' => env('SEED_DEMO_DATA', false),

];
