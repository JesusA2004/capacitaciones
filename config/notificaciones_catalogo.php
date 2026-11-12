<?php

/*
|--------------------------------------------------------------------------
| Catálogo de avisos (fuente única del checklist de Roles y permisos)
|--------------------------------------------------------------------------
|
| Una clave por cada acción real que puede notificar. La clave es el `evento`
| exacto que pasa a NotificacionEventosService (o la clave del envío directo);
| tests/Feature/Notificaciones/CatalogoNotificacionesTest.php falla si se emite
| una clave que no esté aquí.
|
| - modulo: grupo en el checklist (ver `modulos`).
| - audiencia:
|     personal  → le llega a la persona sobre su propio trámite. Bloqueada.
|     asignada  → le llega a quien se le asignó por persona (aprobador,
|                 evaluador, quien envió). Bloqueada.
|     operativa → le llega a un rol por organigrama o permiso. Se marca por rol.
| - defecto: roles marcados de fábrica; '*' = todos.
| - obligatorios: roles que deben aprobar la acción. No se pueden desmarcar.
| - alias: otras claves que son la misma acción (pendiente del reconciliador).
| - push_titulo: título neutro de la pantalla bloqueada; sin él, la etiqueta.
|
| Marcar un rol nunca amplía su alcance ni le da permiso de actuar. Desmarcarlo
| quita el aviso, no la tarea de «Mis pendientes».
*/

$rh = ['rh_admin', 'rh_auxiliar'];
$gerencia = ['gerente_sucursal', 'gerente'];
$linea = ['gerente_sucursal', 'gerente', 'gerente_regional'];

return [

    // Qué roles se evalúan de forma explícita; un rol fuera de esta lista (hecho
    // a mano, o de colaborador) nace marcado en todo: sigue recibiendo lo que
    // su permiso y alcance le den, y se puede desmarcar.
    'roles_explicitos' => [
        'super_admin', 'rh_admin', 'rh_auxiliar', 'gerente_sucursal', 'gerente', 'subgerente',
        'gerente_regional', 'director_comercial', 'direccion', 'sistemas', 'juridico',
        'contraloria', 'auditor',
    ],

    // Roles de persona sin operación propia: sólo cuentan si la cuenta no tiene otro.
    'roles_base' => [
        'colaborador', 'coordinadora', 'coordinadora_regional', 'supervisor', 'jefe_directo',
        'instructor', 'administrador_capacitacion',
    ],

    // Quién edita el checklist: permiso notificaciones_rol.editar (rh_admin, sistemas, super_admin).
    'modulos' => [
        'solicitudes' => 'Solicitudes y vacaciones',
        'reclutamiento' => 'Reclutamiento y selección',
        'contratacion' => 'Contratación y expediente',
        'onboarding' => 'Onboarding e inducción',
        'periodo_prueba' => 'Cierre del periodo de prueba',
        'bajas' => 'Bajas y cierres laborales',
        'nomina' => 'Nómina y préstamos',
        'comunicacion' => 'Avisos y celebraciones',
        'organizacion' => 'Organigrama y documentos maestros',
        'capacitacion' => 'Capacitación',
        'cuenta' => 'Cuenta y diagnóstico',
    ],

    'eventos' => [

        // ─────────────── Solicitudes y vacaciones ───────────────
        'solicitud_creada' => [
            'etiqueta' => 'Nueva solicitud por revisar (permiso, vacaciones, préstamo, corrección de datos)',
            'push_titulo' => 'Nueva solicitud por revisar',
            'modulo' => 'solicitudes',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin', ...$linea, 'direccion'],
            'obligatorios' => ['rh_admin'],
            'alias' => ['correccion_por_revisar', 'vacaciones_pendiente', 'permiso_pendiente', 'prestamo_pendiente'],
        ],
        'solicitud_visto_bueno' => [
            'etiqueta' => 'Solicitud de tu equipo por dar visto bueno',
            'modulo' => 'solicitudes',
            'audiencia' => 'asignada',
        ],
        'solicitud_lista_para_autorizar' => [
            'etiqueta' => 'Solicitud con vistos buenos, lista para autorizar',
            'modulo' => 'solicitudes',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin', 'direccion'],
            'obligatorios' => ['rh_admin'],
        ],
        'solicitud_aprobacion_bloqueada' => [
            'etiqueta' => 'Solicitud detenida porque el organigrama no tiene quién la apruebe',
            'modulo' => 'solicitudes',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'solicitud_paso_aprobado' => [
            'etiqueta' => 'Tu solicitud avanzó a su siguiente aprobación',
            'modulo' => 'solicitudes',
            'audiencia' => 'personal',
        ],
        'solicitud_actualizada' => [
            'etiqueta' => 'Tu solicitud fue aprobada, rechazada o pide corrección',
            'push_titulo' => 'Tu solicitud se actualizó',
            'modulo' => 'solicitudes',
            'audiencia' => 'personal',
        ],
        'solicitud_reabierta' => [
            'etiqueta' => 'Tu solicitud se reabrió',
            'modulo' => 'solicitudes',
            'audiencia' => 'personal',
        ],
        'correccion_respondida' => [
            'etiqueta' => 'El colaborador respondió una corrección de datos',
            'push_titulo' => 'Corrección respondida por revisar',
            'modulo' => 'solicitudes',
            'audiencia' => 'operativa',
            'defecto' => $rh,
            'obligatorios' => ['rh_admin'],
        ],
        'correccion_por_responder' => [
            'etiqueta' => 'RH te pidió más información sobre tu corrección',
            'modulo' => 'solicitudes',
            'audiencia' => 'personal',
        ],
        'correccion_datos' => [
            'etiqueta' => 'RH corrigió un dato de tu expediente',
            'modulo' => 'solicitudes',
            'audiencia' => 'personal',
        ],
        'vacaciones_actualizadas' => [
            'etiqueta' => 'Tus vacaciones fueron aprobadas o rechazadas',
            'push_titulo' => 'Tus vacaciones se actualizaron',
            'modulo' => 'solicitudes',
            'audiencia' => 'personal',
        ],

        // ─────────────── Reclutamiento ───────────────
        'candidato_revision_perfil' => [
            'etiqueta' => 'Candidato nuevo: revisar perfil',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'candidato_entrevista' => [
            'etiqueta' => 'Candidato por entrevistar (según el puesto objetivo)',
            'push_titulo' => 'Candidato por entrevistar',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => [...$linea, 'director_comercial', 'rh_admin'],
            'obligatorios' => [...$linea, 'director_comercial'],
        ],
        'candidato_psicometricas' => [
            'etiqueta' => 'Candidato con psicométricas por registrar',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'candidato_revision_psicometricas' => [
            'etiqueta' => 'Candidato con psicométricas por revisar',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            // El gerente solo aprueba la entrevista (2026-10-22/26): estos
            // pasos son de Reclutamiento/RH y no le llegan por defecto.
            'defecto' => $rh,
        ],
        'candidato_socioeconomico' => [
            'etiqueta' => 'Candidato con estudio socioeconómico pendiente',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'candidato_referencias' => [
            'etiqueta' => 'Candidato con referencias por validar',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'candidato_preautorizacion' => [
            'etiqueta' => 'Candidato por preautorizar',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'candidato_autorizacion_rh' => [
            'etiqueta' => 'Candidato pendiente de autorización final de RH',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
            'obligatorios' => ['rh_admin'],
        ],
        'candidato_invitacion' => [
            'etiqueta' => 'Candidato autorizado: generar QR de contratación',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'intervencion_solicitada' => [
            'etiqueta' => 'Gerente pidió intervención sobre un rechazo de RH',
            'push_titulo' => 'Intervención de candidato pendiente',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => ['gerente_regional', 'director_comercial'],
            'obligatorios' => ['gerente_regional', 'director_comercial'],
            'alias' => ['candidato_intervencion_pendiente'],
        ],
        'intervencion_aprobada' => [
            'etiqueta' => 'Se aprobó la intervención que pediste',
            'modulo' => 'reclutamiento',
            'audiencia' => 'asignada',
        ],
        'intervencion_rechazo_confirmado' => [
            'etiqueta' => 'Se confirmó el rechazo del candidato de tu intervención',
            'modulo' => 'reclutamiento',
            'audiencia' => 'asignada',
        ],
        'agenda_evento_agendado' => [
            'etiqueta' => 'Cita de reclutamiento agendada en tu sucursal',
            'push_titulo' => 'Nueva cita agendada',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => [...$linea, 'subgerente', 'director_comercial'],
        ],
        'agenda_evento_reprogramado' => [
            'etiqueta' => 'Cita de reclutamiento reprogramada',
            'push_titulo' => 'Cita reprogramada',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => [...$linea, 'subgerente', 'director_comercial'],
        ],
        'agenda_evento_cancelado' => [
            'etiqueta' => 'Cita de reclutamiento cancelada',
            'push_titulo' => 'Cita cancelada',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => [...$linea, 'subgerente', 'director_comercial'],
        ],
        'agenda_recordatorio' => [
            'etiqueta' => 'Recordatorio de cita de reclutamiento',
            'push_titulo' => 'Recordatorio de cita',
            'modulo' => 'reclutamiento',
            'audiencia' => 'operativa',
            'defecto' => [...$linea, 'subgerente', 'director_comercial'],
        ],

        // ─────────────── Contratación y expediente ───────────────
        'colaborador_alta' => [
            'etiqueta' => 'Alta de colaborador: preparar cuenta, accesos y equipo',
            'push_titulo' => 'Alta de colaborador',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => [...$rh, 'sistemas', ...$gerencia],
        ],
        // Entró a contratación: Sistemas le da sus usuarios en las demás
        // plataformas (bandeja «Pendientes de alta en sistemas»).
        'sistemas_alta_pendiente' => [
            'etiqueta' => 'Alta pendiente en sistemas: dar usuarios en las demás plataformas',
            'push_titulo' => 'Alta pendiente en sistemas',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => ['sistemas'],
        ],
        'alta_activada' => [
            'etiqueta' => 'Bienvenida: tu cuenta quedó activa',
            'push_titulo' => '¡Bienvenido a MR. LANA!',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'activacion_pendiente' => [
            'etiqueta' => 'Alta pendiente de activar',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'expediente_completo' => [
            'etiqueta' => 'Expediente completo: generar paquete de contratación',
            'push_titulo' => 'Expediente completo',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'contrato_pendiente' => [
            'etiqueta' => 'Contrato pendiente de generar',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'contratos_listos' => [
            'etiqueta' => 'Contratos listos para imprimir y recabar firma',
            'push_titulo' => 'Contratos listos para imprimir',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => [...$rh, ...$gerencia],
            'alias' => ['impresion_pendiente'],
        ],
        'firma_fisica_pendiente' => [
            'etiqueta' => 'Documento impreso con firma física pendiente',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => [...$rh, ...$gerencia],
        ],
        'original_fisico_pendiente' => [
            'etiqueta' => 'Original firmado por enviar, recibir o escanear',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => [...$rh, ...$gerencia],
            'alias' => ['envio_original_pendiente', 'recepcion_original_pendiente', 'escaneo_pendiente'],
        ],
        'documento_subido' => [
            'etiqueta' => 'Documento del expediente por revisar',
            'push_titulo' => 'Documento por revisar',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
            'obligatorios' => $rh,
            'alias' => ['documento_por_revisar'],
        ],
        'documento_revisado' => [
            'etiqueta' => 'Tu documento fue aprobado o rechazado',
            'push_titulo' => 'Tu documento fue revisado',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
            'alias' => ['documento_rechazado'],
        ],
        'incorporacion_completa' => [
            'etiqueta' => 'Incorporación lista para la revisión final',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
            'obligatorios' => $rh,
        ],
        'incorporacion_decidida' => [
            'etiqueta' => 'Tu incorporación fue aprobada o devuelta',
            'push_titulo' => 'Tu incorporación se actualizó',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'carta_compromiso_generada' => [
            'etiqueta' => 'Carta compromiso de documentos generada',
            'push_titulo' => 'Carta compromiso de documentos',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'carta_compromiso_por_firmar' => [
            'etiqueta' => 'Firma tu carta compromiso',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        // La propia persona pidió su carta (Mi expediente / app): espera la
        // aprobación de quien tenga cartas_compromiso.aprobar.
        'carta_compromiso_solicitada' => [
            'etiqueta' => 'Carta compromiso por aprobar',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
            'obligatorios' => $rh,
        ],
        'carta_compromiso_rechazada' => [
            'etiqueta' => 'RH no aprobó tu carta compromiso',
            'push_titulo' => 'Tu carta compromiso se actualizó',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'documento_firma_pendiente' => [
            'etiqueta' => 'Tienes un documento por firmar',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
            'alias' => ['firma_pendiente'],
        ],
        'expediente_incompleto' => [
            'etiqueta' => 'Completa tu expediente',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'datos_faltantes' => [
            'etiqueta' => 'RH te pidió completar tu información',
            'push_titulo' => 'Completa tu información',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'informacion_faltante' => [
            'etiqueta' => 'RH te pidió completar tu información',
            'push_titulo' => 'Completa tu información',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'solicitud_empleo_faltante' => [
            'etiqueta' => 'RH te pidió completar tu solicitud de empleo',
            'push_titulo' => 'Completa tu solicitud de empleo',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'rh_foto_perfil' => [
            'etiqueta' => 'Cambio de foto de perfil por revisar',
            'modulo' => 'contratacion',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
            'obligatorios' => ['rh_admin'],
        ],
        'foto_perfil' => [
            'etiqueta' => 'Tu foto de perfil fue aprobada o rechazada',
            'push_titulo' => 'Tu foto de perfil se revisó',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],
        'responsiva_registrada' => [
            'etiqueta' => 'Se generó una responsiva de activos a tu nombre',
            'push_titulo' => 'Responsiva generada',
            'modulo' => 'contratacion',
            'audiencia' => 'personal',
        ],

        // ─────────────── Onboarding ───────────────
        'induccion_asignada' => [
            'etiqueta' => 'Tienes una inducción asignada',
            'modulo' => 'onboarding',
            'audiencia' => 'personal',
            'alias' => ['onboarding_habilitado', 'onboarding_modulo_pendiente'],
        ],
        'induccion_reevaluacion_habilitada' => [
            'etiqueta' => 'Ya puedes volver a presentar tu evaluación',
            'modulo' => 'onboarding',
            'audiencia' => 'personal',
            'alias' => ['onboarding_reevaluacion'],
        ],
        'induccion_evaluacion_disponible' => [
            'etiqueta' => 'Contesta tu evaluación de inducción',
            'modulo' => 'onboarding',
            'audiencia' => 'personal',
            'alias' => ['induccion_evaluacion_pendiente'],
        ],
        'induccion_evaluacion_presentada' => [
            'etiqueta' => 'Evaluación de inducción por calificar',
            'modulo' => 'onboarding',
            'audiencia' => 'operativa',
            'defecto' => $rh,
            'alias' => ['induccion_casos_por_calificar'],
        ],
        'induccion_evaluacion_aprobada' => [
            'etiqueta' => 'Colaborador aprobó su evaluación de inducción',
            'push_titulo' => 'Evaluación de inducción aprobada',
            'modulo' => 'onboarding',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'induccion_requiere_reforzamiento' => [
            'etiqueta' => 'Colaborador requiere reforzamiento de inducción',
            'push_titulo' => 'Reforzamiento de inducción',
            'modulo' => 'onboarding',
            'audiencia' => 'operativa',
            'defecto' => $rh,
            'alias' => ['induccion_reforzamiento'],
        ],
        'onboarding_refuerzo' => [
            'etiqueta' => 'Onboarding con resultado menor al mínimo',
            'push_titulo' => 'Onboarding por reforzar',
            'modulo' => 'onboarding',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],
        'onboarding_activos' => [
            'etiqueta' => 'Activos y responsivas por entregar a un nuevo ingreso',
            'push_titulo' => 'Activos por entregar',
            'modulo' => 'onboarding',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin', ...$linea],
            'alias' => ['onboarding_entrega_activos'],
        ],
        'descriptivo_puesto_enviado' => [
            'etiqueta' => 'Lee tu descriptivo de puesto',
            'modulo' => 'onboarding',
            'audiencia' => 'personal',
            'alias' => ['descriptivo_puesto_por_acusar'],
        ],
        'descriptivo_puesto_acusado' => [
            'etiqueta' => 'El colaborador se dio por enterado del descriptivo que enviaste',
            'push_titulo' => 'Descriptivo de puesto enterado',
            'modulo' => 'onboarding',
            'audiencia' => 'asignada',
        ],

        // ─────────────── Periodo de prueba ───────────────
        'evaluacion_pendiente' => [
            'etiqueta' => 'Evaluación de periodo de prueba por capturar',
            'modulo' => 'periodo_prueba',
            'audiencia' => 'asignada',
        ],
        'evaluacion_devuelta' => [
            'etiqueta' => 'RH devolvió la evaluación que capturaste',
            'push_titulo' => 'Evaluación devuelta',
            'modulo' => 'periodo_prueba',
            'audiencia' => 'asignada',
        ],
        'evaluacion_capturada' => [
            'etiqueta' => 'Evaluación de periodo de prueba por autorizar',
            'modulo' => 'periodo_prueba',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin', 'direccion'],
            'obligatorios' => ['rh_admin'],
            'alias' => ['evaluacion_por_autorizar'],
        ],
        'contrato_por_vencer' => [
            'etiqueta' => 'Contrato por vencer: ya se debe renovar',
            'push_titulo' => 'Contrato por vencer',
            'modulo' => 'periodo_prueba',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin', ...$gerencia],
        ],
        'excepcion_periodo_prueba_solicitada' => [
            'etiqueta' => 'Excepción de renovación por autorizar',
            'modulo' => 'periodo_prueba',
            'audiencia' => 'operativa',
            'defecto' => ['direccion'],
            'obligatorios' => ['direccion'],
            'alias' => ['excepcion_periodo_prueba_pendiente'],
        ],
        'excepcion_periodo_prueba_resuelta' => [
            'etiqueta' => 'Se resolvió la excepción de renovación que pediste',
            'push_titulo' => 'Excepción de renovación resuelta',
            'modulo' => 'periodo_prueba',
            'audiencia' => 'asignada',
        ],

        // ─────────────── Bajas ───────────────
        'cierre_baja_solicitada' => [
            'etiqueta' => 'Baja registrada: accesos suspendidos',
            'push_titulo' => 'Baja registrada',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => [...$rh, 'gerente_regional', 'sistemas'],
        ],
        'colaborador_baja' => [
            'etiqueta' => 'Baja ejecutada: retirar accesos, cuentas y equipo',
            'push_titulo' => 'Baja: retirar accesos',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => [...$rh, 'sistemas'],
        ],
        'baja_registrada' => [
            'etiqueta' => 'Confirmación de la baja que registraste',
            'modulo' => 'bajas',
            'audiencia' => 'personal',
        ],
        'baja_resuelta' => [
            'etiqueta' => 'La baja que registraste se cerró o no procedió',
            'push_titulo' => 'La baja que registraste se resolvió',
            'modulo' => 'bajas',
            'audiencia' => 'asignada',
        ],
        'cierre_negativa_firma' => [
            'etiqueta' => 'Negativa de firma: generar acta con dos testigos',
            'push_titulo' => 'Negativa de firma registrada',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'baja_alerta_contraloria' => [
            'etiqueta' => 'Baja por faltantes o probidad: aviso a Contraloría',
            'push_titulo' => 'Baja para revisión de Contraloría',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['contraloria'],
            'obligatorios' => ['contraloria'],
        ],
        'baja_alerta_juridico' => [
            'etiqueta' => 'Baja por probidad o aviso fuera de plazo: aviso a Jurídico',
            'push_titulo' => 'Baja para revisión de Jurídico',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['juridico'],
            'obligatorios' => ['juridico'],
        ],
        'baja_rotacion_temprana' => [
            'etiqueta' => 'Baja con menos de 90 días de antigüedad',
            'push_titulo' => 'Rotación temprana',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'baja_alerta_clima_laboral' => [
            'etiqueta' => 'Baja por clima laboral: entrevista de salida obligatoria (también por correo)',
            'push_titulo' => 'Alerta de clima laboral',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'finiquito_pendiente' => [
            'etiqueta' => 'Finiquito por calcular',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'finiquito_por_autorizar' => [
            'etiqueta' => 'Finiquito por entregar',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'cierre_por_concluir' => [
            'etiqueta' => 'Cierre laboral por concluir',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'reingreso_solicitado' => [
            'etiqueta' => 'Reingreso por decidir',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
            'obligatorios' => ['rh_admin'],
            'alias' => ['reingreso_preautorizacion', 'reingreso_revision'],
        ],
        'reingreso_autorizado' => [
            'etiqueta' => 'Tu reingreso fue autorizado',
            'modulo' => 'bajas',
            'audiencia' => 'personal',
        ],
        'acta_registrada' => [
            'etiqueta' => 'Acta administrativa registrada',
            'modulo' => 'bajas',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],

        // ─────────────── Nómina y préstamos ───────────────
        'recibo_nomina' => [
            'etiqueta' => 'Tu recibo de nómina está disponible',
            'modulo' => 'nomina',
            'audiencia' => 'personal',
        ],
        'prestamo_autorizado' => [
            'etiqueta' => 'Tu préstamo fue autorizado',
            'modulo' => 'nomina',
            'audiencia' => 'personal',
        ],
        'prestamo_disponible' => [
            'etiqueta' => 'Ya puedes solicitar un préstamo',
            'modulo' => 'nomina',
            'audiencia' => 'personal',
        ],

        // ─────────────── Avisos y celebraciones ───────────────
        'aviso_rh' => [
            'etiqueta' => 'Aviso que RH envía a toda la empresa o a una persona',
            'push_titulo' => 'Nuevo aviso de Recursos Humanos',
            'modulo' => 'comunicacion',
            'audiencia' => 'personal',
        ],
        'cumpleanos' => [
            'etiqueta' => 'Tu felicitación de cumpleaños',
            'push_titulo' => '¡Feliz cumpleaños!',
            'modulo' => 'comunicacion',
            'audiencia' => 'personal',
        ],
        'aniversario_laboral' => [
            'etiqueta' => 'Tu felicitación de aniversario laboral',
            'push_titulo' => '¡Feliz aniversario!',
            'modulo' => 'comunicacion',
            'audiencia' => 'personal',
        ],
        'celebracion_mensaje' => [
            'etiqueta' => 'Te dejaron una felicitación en tu muro',
            'push_titulo' => 'Tienes una nueva felicitación',
            'modulo' => 'comunicacion',
            'audiencia' => 'personal',
        ],
        'cumpleanos_general' => [
            'etiqueta' => 'Hoy es el cumpleaños de un compañero',
            'push_titulo' => 'Hoy hay un cumpleaños en MR. LANA',
            'modulo' => 'comunicacion',
            'audiencia' => 'operativa',
            'defecto' => ['*'],
            'alias' => ['aniversario_general'],
        ],
        'cumpleanos_muro' => [
            'etiqueta' => 'Se abrió el muro de felicitaciones de un compañero',
            'push_titulo' => 'Hoy hay una celebración en MR. LANA',
            'modulo' => 'comunicacion',
            'audiencia' => 'operativa',
            'defecto' => ['*'],
        ],
        'rh_cumpleanos' => [
            'etiqueta' => 'Recordatorio diario de cumpleaños del equipo',
            'push_titulo' => 'Cumpleaños de hoy',
            'modulo' => 'comunicacion',
            'audiencia' => 'operativa',
            'defecto' => $rh,
        ],

        // ─────────────── Organigrama y documentos maestros ───────────────
        'organigrama_sin_jefe' => [
            'etiqueta' => 'Personal de sucursal sin jefe asignable',
            'modulo' => 'organizacion',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],
        'plantilla_faltante' => [
            'etiqueta' => 'Falta plantilla o datos para generar un documento',
            'modulo' => 'organizacion',
            'audiencia' => 'operativa',
            'defecto' => ['rh_admin'],
        ],

        // ─────────────── Capacitación (detrás del feature flag) ───────────────
        'capacitacion_asignacion' => [
            'etiqueta' => 'Te asignaron un curso o una fecha límite próxima',
            'modulo' => 'capacitacion',
            'audiencia' => 'personal',
        ],
        'capacitacion_sesion' => [
            'etiqueta' => 'Sesión en vivo programada o próxima',
            'modulo' => 'capacitacion',
            'audiencia' => 'personal',
        ],
        'capacitacion_resultado' => [
            'etiqueta' => 'Tu cuestionario o actividad fue calificado',
            'modulo' => 'capacitacion',
            'audiencia' => 'personal',
        ],
        'capacitacion_calificaciones' => [
            'etiqueta' => 'Tienes calificaciones pendientes como instructor',
            'modulo' => 'capacitacion',
            'audiencia' => 'asignada',
        ],

        // ─────────────── Cuenta y diagnóstico ───────────────
        'cuenta_credenciales' => [
            'etiqueta' => 'Tus accesos y credenciales (por correo)',
            'modulo' => 'cuenta',
            'audiencia' => 'personal',
        ],
        'cuenta_contrasena_restablecida' => [
            'etiqueta' => 'Alguien restableció tu contraseña',
            'push_titulo' => 'Tu contraseña cambió',
            'modulo' => 'cuenta',
            'audiencia' => 'personal',
        ],
        'prueba_notificacion' => [
            'etiqueta' => 'Notificación de prueba',
            'modulo' => 'cuenta',
            'audiencia' => 'personal',
        ],
    ],
];
