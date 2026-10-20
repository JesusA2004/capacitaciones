<?php

use App\Enums\FuenteDomicilioPatron;
use App\Enums\TipoBaja;

/*
|--------------------------------------------------------------------------
| Catálogo de la configuración administrable (Administración → Configuración)
|--------------------------------------------------------------------------
|
| Fuente versionada de QUÉ se puede configurar: clave, grupo, tipo,
| descripción, valor por defecto y validación. La tabla
| configuraciones_sistema solo guarda los valores que el negocio cambió
| (App\Services\Configuracion\ConfiguracionSistemaService).
|
| Las reglas jurídicas y de seguridad NO viven aquí: "RH es la autorización
| final", la separación preautoriza/autoriza y el alcance organizacional
| están en código y no se pueden apagar desde la pantalla.
|
| 'config' => clave de config() que el valor guardado reemplaza en tiempo de
| ejecución (así el resto del código sigue leyendo config() sin saber que
| el valor viene de la base de datos).
| 'css' => variable CSS que pinta el color en la web.
| 'tema' => nombre semántico que recibe la app móvil (GET /api/v1/app/theme).
|
*/

$color = fn (string $etiqueta, string $defecto, string $css, string $tema, string $descripcion) => [
    'grupo' => 'apariencia',
    'tipo' => 'color',
    'etiqueta' => $etiqueta,
    'descripcion' => $descripcion,
    'defecto' => $defecto,
    'reglas' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
    'css' => $css,
    'tema' => $tema,
];

$grafica = fn (string $etiqueta, string $defecto, string $css, string $descripcion) => [
    'grupo' => 'apariencia',
    'seccion' => 'graficas',
    'tipo' => 'color',
    'etiqueta' => $etiqueta,
    'descripcion' => $descripcion,
    'defecto' => $defecto,
    'reglas' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
    'css' => $css,
];

return [

    'parametros' => [
        'apariencia.primary' => $color('Primario', '#315B59', '--mrl-petroleo', 'primary', 'Petróleo suavizado: encabezados, botones principales y enlaces.'),
        'apariencia.primary_alt' => $color('Primario alterno', '#284B49', '--mrl-petroleo-2', 'primaryAlt', 'Variante del primario para estados hover y degradados.'),
        'apariencia.brand_green' => $color('Verde de marca', '#3F7558', '--mrl-verde', 'success', 'Éxito, aprobado, completado.'),
        'apariencia.deep_green' => $color('Verde profundo', '#315B59', '--mrl-verde-profundo', 'deepGreen', 'Acentos de marca sobre fondos claros.'),
        'apariencia.secondary_green' => $color('Verde secundario', '#6B7F4A', '--mrl-verde-secundario', 'secondary', 'Acentos secundarios y gráficas.'),
        'apariencia.gold' => $color('Dorado', '#C7A66B', '--mrl-dorado', 'gold', 'Acento institucional y estados "en proceso".'),
        'apariencia.gold_dark' => $color('Dorado oscuro', '#8A6A36', '--mrl-dorado-oscuro', 'goldDark', 'Advertencias y texto sobre dorado.'),
        'apariencia.muted' => $color('Gris verdoso', '#A9B0A3', '--mrl-gris-verdoso', 'muted', 'Elementos deshabilitados o secundarios.'),
        'apariencia.navy' => $color('Esmeralda', '#6E9B8F', '--mrl-navy', 'navy', 'Datos y gráficas complementarias.'),
        'apariencia.accent' => $color('Acento', '#6E9B8F', '--mrl-cyan', 'accent', 'Datos destacados e información.'),
        'apariencia.danger' => $color('Peligro', '#B0524A', '--mrl-rojo', 'danger', 'Rechazos, errores y alertas.'),
        'apariencia.background' => $color('Fondo', '#FBF8F2', '--mrl-fondo', 'background', 'Fondo general de las pantallas.'),
        'apariencia.surface' => $color('Superficie', '#FFFDF9', '--mrl-superficie', 'surface', 'Tarjetas, paneles y diálogos.'),

        // Un color por gráfica del tablero de RH (sección "Gráficas" de
        // Apariencia). La app no los usa.
        'apariencia.grafica_plantilla' => $grafica('Plantilla activa', '#6E9B8F', '--grafica-plantilla', 'Barra de avance de la tarjeta «Plantilla activa».'),
        'apariencia.grafica_rotacion' => $grafica('Rotación mensual', '#315B59', '--grafica-rotacion', 'Línea y área de la gráfica de rotación mes a mes.'),
        'apariencia.grafica_cobertura' => $grafica('Cobertura de plantilla', '#7FA88F', '--grafica-cobertura', 'Anillo de plazas autorizadas ocupadas.'),
        'apariencia.grafica_embudo' => $grafica('Embudo de reclutamiento', '#C7A66B', '--grafica-embudo', 'Barras de cada etapa del reclutamiento.'),
        'apariencia.grafica_tiempo' => $grafica('Tiempo de contratación por nivel', '#A98252', '--grafica-tiempo', 'Barras de días promedio para contratar por nivel de puesto.'),
        'apariencia.grafica_sucursales' => $grafica('Plantilla por sucursal', '#9FB38F', '--grafica-sucursales', 'Barras de ocupación de cada sucursal.'),

        'rh.onboarding_calificacion_minima' => [
            'grupo' => 'rh',
            'tipo' => 'decimal',
            'etiqueta' => 'Calificación mínima de onboarding',
            'descripcion' => 'Mínimo aprobatorio (0–10) de la inducción institucional y de los módulos del puesto. Debajo de este valor RH da refuerzo y habilita la reevaluación.',
            'defecto' => 8,
            'reglas' => ['required', 'numeric', 'min:1', 'max:10'],
            'config' => 'ciclo_laboral.onboarding.calificacion_minima',
        ],
        'rh.dias_aviso_vencimiento' => [
            'grupo' => 'rh',
            'tipo' => 'entero',
            'etiqueta' => 'Días de aviso antes del fin del contrato',
            'descripcion' => 'Cuántos días antes de que venza el contrato de capacitación se abre la evaluación y se avisa que ya se debe renovar (15 → un gestor con contrato de 2 meses se avisa al mes y 15 días).',
            'defecto' => 15,
            'reglas' => ['required', 'integer', 'min:1', 'max:60'],
            'config' => 'contratos.dias_aviso_vencimiento',
        ],
        // Sin duración global por defecto: cada puesto debe tener la suya
        // (puestos.meses_periodo_prueba); sin ella no se contrata.
        'rh.evaluacion_calificacion_minima' => [
            'grupo' => 'rh',
            'tipo' => 'decimal',
            'etiqueta' => 'Calificación mínima del periodo de prueba',
            'descripcion' => 'Promedio a partir del cual la evaluación del jefe se considera aprobada (la decisión final sigue siendo de RH).',
            'defecto' => 7,
            'reglas' => ['required', 'numeric', 'min:1', 'max:10'],
            'config' => 'contratos.calificacion_minima_aprobatoria',
        ],
        'rh.causas_cierre_solicitables' => [
            'grupo' => 'rh',
            'tipo' => 'lista',
            'etiqueta' => 'Tipos de baja que puede iniciar un jefe',
            'descripcion' => 'RH siempre puede usar cualquier tipo; un jefe/gerente solo los marcados. El motivo (causal de rotación) se administra en Bajas y finiquitos.',
            'defecto' => ['renuncia', 'rescision', 'fin_contrato'],
            'reglas' => ['required', 'array', 'min:1'],
            'opciones_enum' => TipoBaja::class,
            'config' => 'ciclo_laboral.cierre.causas_solicitables',
        ],
        'rh.domicilio_patron_documentos' => [
            'grupo' => 'rh',
            'tipo' => 'opcion',
            'etiqueta' => 'Domicilio del patrón en contratos y convenios',
            'descripcion' => 'Qué domicilio se escribe donde el documento dice «…el ubicado en». Fiscal: el de la empresa (Empresas). Sucursal: el de la sucursal del colaborador (Sucursales); si la sucursal no tiene domicilio capturado se usa el fiscal.',
            'defecto' => 'fiscal',
            'reglas' => ['required', 'string', 'in:fiscal,sucursal'],
            'opciones_enum' => FuenteDomicilioPatron::class,
            'config' => 'documentos_maestros.domicilio_patron',
        ],
    ],

    /*
    | Eventos que notifican. 'destinatarios' por defecto = el comportamiento
    | histórico; RH/Administración puede cambiarlos sin desplegar. Siempre se
    | quitan duplicados, cuentas bloqueadas/inactivas y a la persona que
    | ejecutó la acción cuando no tiene sentido avisarle.
    */
    'eventos' => [
        'solicitud_creada' => [
            'etiqueta' => 'Solicitud administrativa creada',
            'descripcion' => 'Vacaciones, permisos, préstamos, incapacidades y cambios administrativos que hace un colaborador.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'rh.solicitudes.aprobar',
            'fallback' => ['rh'],
        ],
        'solicitud_visto_bueno' => [
            'etiqueta' => 'Solicitud que requiere visto bueno (gerente y regional)',
            'descripcion' => 'Aviso a quien debe dar el siguiente visto bueno antes de que la solicitud llegue a RH.',
            'destinatarios' => ['aprobador'],
            'fallback' => ['jefe_directo'],
        ],
        'candidato_preautorizado' => [
            'etiqueta' => 'Candidato preautorizado (reclutamiento)',
            'descripcion' => 'El gerente preautorizó al candidato; RH debe dar la autorización final.',
            'destinatarios' => ['rh'],
            'fallback' => ['gerencia_rh'],
        ],
        'expediente_completo' => [
            'etiqueta' => 'Expediente completo (generar paquete de contratación)',
            'descripcion' => 'El expediente del colaborador quedó completo: RH genera el paquete de contratación de su puesto desde su ficha. Solo quien puede generar documentos y tiene a la persona dentro de su alcance; nunca toda la plantilla.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'documentos_laborales.generar',
            'fallback' => ['rh'],
        ],
        'contratos_listos' => [
            'etiqueta' => 'Contratos listos para imprimir',
            'descripcion' => 'Expediente completo: hay que imprimir, recabar firma/huella y enviar el original.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'documentos_laborales.operar_fisico',
            'fallback' => ['rh'],
        ],
        'onboarding_refuerzo' => [
            'etiqueta' => 'Onboarding con calificación menor al mínimo',
            'descripcion' => 'RH da retroalimentación y habilita la reevaluación.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'onboarding.gestionar',
            'fallback' => ['gerencia_rh'],
        ],
        'induccion_evaluacion_presentada' => [
            'etiqueta' => 'Evaluación de inducción por calificar',
            'descripcion' => 'El colaborador presentó su evaluación de inducción: faltan los casos prácticos (RH).',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'onboarding.gestionar',
            'fallback' => ['gerencia_rh'],
        ],
        'induccion_evaluacion_aprobada' => [
            'etiqueta' => 'Evaluación de inducción aprobada',
            'descripcion' => 'El colaborador aprobó su evaluación de inducción.',
            'destinatarios' => ['usuarios_con_permiso', 'jefe_directo'],
            'permiso' => 'onboarding.gestionar',
            'fallback' => ['gerencia_rh'],
        ],
        'induccion_requiere_reforzamiento' => [
            'etiqueta' => 'Reforzamiento de inducción requerido',
            'descripcion' => 'El colaborador obtuvo menos de 24/30: RH debe retroalimentar y reaplicar en máximo 5 días hábiles.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'onboarding.gestionar',
            'fallback' => ['gerencia_rh'],
        ],
        'evaluacion_pendiente' => [
            'etiqueta' => 'Evaluación de periodo de prueba por capturar',
            'descripcion' => 'Se habilitó la evaluación del periodo de prueba.',
            'destinatarios' => ['evaluador'],
            'fallback' => ['jefe_directo', 'gerente'],
        ],
        'contrato_por_vencer' => [
            'etiqueta' => 'Ya se debe renovar el contrato',
            'descripcion' => 'El contrato de capacitación/inducción está por vencer (N días hábiles antes, ver Parámetros de RH). Solo gerencia de sucursal y RH: Regional/Dirección Comercial no se notifican por un simple vencimiento, solo si el gerente solicita intervención.',
            'destinatarios' => ['usuarios_con_permiso', 'responsable_sucursal', 'gerencia_rh'],
            'permiso' => 'evaluaciones.autorizar',
            'fallback' => ['rh'],
        ],
        'evaluacion_capturada' => [
            'etiqueta' => 'Evaluación de periodo de prueba por autorizar',
            'descripcion' => 'El jefe recomendó renovar o no renovar; RH decide.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'evaluaciones.autorizar',
            'fallback' => ['rh'],
        ],
        'excepcion_periodo_prueba_solicitada' => [
            'etiqueta' => 'Excepción de renovación por autorizar',
            'descripcion' => 'RH solicitó una excepción a la regla canónica de renovación del periodo de prueba (FO-RH-EPP-001). Solo Dirección General decide.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'excepciones.autorizar',
            'fallback' => [],
        ],
        'excepcion_periodo_prueba_resuelta' => [
            'etiqueta' => 'Excepción de renovación resuelta',
            'descripcion' => 'Dirección General aprobó o rechazó la excepción solicitada.',
            'destinatarios' => ['creador'],
            'fallback' => ['rh'],
        ],
        'evaluacion_devuelta' => [
            'etiqueta' => 'Evaluación devuelta para corrección',
            'descripcion' => 'RH pidió corregir la evaluación del periodo de prueba.',
            'destinatarios' => ['evaluador'],
            'fallback' => ['jefe_directo'],
        ],
        // Módulo de Bajas y Causales (docs/BAJAS_Y_CAUSALES.md).
        'baja_por_revisar' => [
            'etiqueta' => 'Baja por revisar (RH)',
            'descripcion' => 'Jurídico aprobó o devolvió una baja: RH la completa (causal, documentos, fechas) y la cierra.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'bajas.gestionar',
            'fallback' => ['rh'],
        ],
        'baja_revision_juridico' => [
            'etiqueta' => 'Baja por revisar (Jurídico)',
            'descripcion' => 'La causal requiere revisión de Jurídico antes del cierre (RN-06).',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'bajas.revisar_juridico',
            'fallback' => ['rh'],
        ],
        'baja_alerta_clima_laboral' => [
            'etiqueta' => 'Alerta de clima laboral (RV-04, con correo)',
            'descripcion' => 'Baja por relación con el jefe o clima laboral: entrevista de salida obligatoria por RH y revisión conforme a NOM-035. Se envía también por correo.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'bajas.gestionar',
            'fallback' => ['rh'],
        ],
        'baja_alerta_contraloria' => [
            'etiqueta' => 'Aviso a Contraloría (RE-02 / RE-03)',
            'descripcion' => 'Baja por faltantes en caja o cartera, o por falta de probidad, robo o fraude.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'bajas.consulta_contraloria',
            'fallback' => ['rh'],
        ],
        'baja_alerta_juridico' => [
            'etiqueta' => 'Aviso a Jurídico (RE-03 / aviso ante Tribunal fuera de plazo)',
            'descripcion' => 'Baja por falta de probidad, robo o fraude, o aviso de rescisión entregado ante el Tribunal después de 5 días hábiles (RN-08).',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'bajas.revisar_juridico',
            'fallback' => ['rh'],
        ],
        'baja_rotacion_temprana' => [
            'etiqueta' => 'Rotación temprana (antigüedad menor a 90 días)',
            'descripcion' => 'La persona se va con menos de 90 días de antigüedad.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'bajas.gestionar',
            'fallback' => ['rh'],
        ],
        'cierre_baja_solicitada' => [
            'etiqueta' => 'Baja registrada (accesos suspendidos)',
            'descripcion' => 'El gerente o RH registró una baja (directa, sin aprobación): quién la dio, a quién, forma · causal y fecha. Sus accesos ya se suspendieron. RH, regionales y Sistemas (retirar accesos, cuentas y equipo) se enteran; RH puede rehabilitarlos.',
            'destinatarios' => ['regional', 'rh', 'sistemas'],
            'fallback' => ['gerencia_rh'],
        ],
        // LEGACY ('legacy' => true): claves conservadas solo para leer avisos y
        // pendientes anteriores. Flujo vigente de la baja: jefe directo o RH
        // la inicia → documentos → finiquito → registrar entrega → cierre,
        // sin preautorización ni autorización posterior de RH (ni devolver/
        // reenviar). NotificadorRhService nunca emite un evento legacy.
        'cierre_preautorizacion' => [
            'etiqueta' => 'Legacy: baja por preautorizar (flujo anterior)',
            'descripcion' => 'LEGACY: ya no se emite. La baja la aplica de inmediato el jefe directo o RH, sin preautorización; solo registros anteriores traen este aviso.',
            'destinatarios' => ['aprobador'],
            'fallback' => [],
            'legacy' => true,
        ],
        'cierre_autorizacion_rh' => [
            'etiqueta' => 'Legacy: baja pendiente de autorización de RH (flujo anterior)',
            'descripcion' => 'LEGACY: ya no se emite. La baja la aplica de inmediato el jefe directo o RH, sin autorización posterior de RH; solo registros anteriores traen este aviso.',
            'destinatarios' => ['rh'],
            'fallback' => ['gerencia_rh'],
            'legacy' => true,
        ],
        'cierre_negativa_firma' => [
            'etiqueta' => 'El colaborador se negó a firmar/recibir la baja',
            'descripcion' => 'Se registró que el colaborador se negó a firmar/recibir los documentos de su baja: RH debe generar el acta de negativa con dos testigos. Solo RH que puede gestionar el cierre y tiene a la persona dentro de su alcance.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'cierres.gestionar',
            'fallback' => ['rh'],
        ],
        'pago_por_programar' => [
            'etiqueta' => 'Legacy: pago de finiquito por programar (flujo anterior)',
            'descripcion' => 'LEGACY: ya no se emite. RH registra la entrega del finiquito, que cierra el cierre laboral (sin programar pago).',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'cierres.programar_pago',
            'fallback' => ['rh'],
            'legacy' => true,
        ],
        'cita_finiquito' => [
            'etiqueta' => 'Legacy: excolaborador por citar (flujo anterior)',
            'descripcion' => 'LEGACY: ya no se emite. RH registra la entrega del finiquito, que cierra el cierre laboral (sin cita).',
            'destinatarios' => ['jefe_directo'],
            'fallback' => ['responsable_sucursal'],
            'legacy' => true,
        ],
        'colaborador_alta' => [
            'etiqueta' => 'Alta de colaborador',
            'descripcion' => 'Un colaborador quedó activo: RH y Sistemas (cuenta, accesos y equipo) y confirmación a su gerente. Nunca Regional por este evento.',
            'destinatarios' => ['rh', 'sistemas', 'gerente'],
            'fallback' => ['gerencia_rh'],
        ],
        'colaborador_baja' => [
            'etiqueta' => 'Baja de colaborador',
            'descripcion' => 'Se ejecutó la baja: RH y Sistemas (retirar accesos, cuentas y equipo).',
            'destinatarios' => ['rh', 'sistemas'],
            'fallback' => ['gerencia_rh'],
        ],
        'reingreso_solicitado' => [
            'etiqueta' => 'Reingreso solicitado',
            'descripcion' => 'Alguien pidió reincorporar a un excolaborador; RH decide.',
            'destinatarios' => ['rh'],
            'fallback' => ['gerencia_rh'],
        ],
    ],

    /*
    | Quién es «Sistemas» para las notificaciones de alta/baja: cuentas con
    | alguno de estos roles o con puesto/departamento con estos nombres.
    | Nunca un usuario fijo.
    */
    'sistemas' => [
        'roles' => ['sistemas'],
        'departamentos' => ['Sistemas'],
    ],
];
