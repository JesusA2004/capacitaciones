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
            'etiqueta' => 'Causas de baja que puede solicitar un jefe',
            'descripcion' => 'RH siempre puede usar cualquier causa; un jefe/gerente solo las marcadas.',
            'defecto' => ['renuncia', 'no_renovacion', 'bajo_desempeno', 'baja_inmediata'],
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
        'evaluacion_pendiente' => [
            'etiqueta' => 'Evaluación de periodo de prueba por capturar',
            'descripcion' => 'Se habilitó la evaluación del periodo de prueba.',
            'destinatarios' => ['evaluador'],
            'fallback' => ['jefe_directo', 'gerente'],
        ],
        'contrato_por_vencer' => [
            'etiqueta' => 'Ya se debe renovar el contrato',
            'descripcion' => 'El contrato de capacitación/inducción está por vencer (N días antes, ver Parámetros de RH).',
            'destinatarios' => ['usuarios_con_permiso', 'responsable_sucursal', 'regional', 'gerencia_rh'],
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
        'evaluacion_devuelta' => [
            'etiqueta' => 'Evaluación devuelta para corrección',
            'descripcion' => 'RH pidió corregir la evaluación del periodo de prueba.',
            'destinatarios' => ['evaluador'],
            'fallback' => ['jefe_directo'],
        ],
        'cierre_baja_solicitada' => [
            'etiqueta' => 'Baja solicitada (accesos suspendidos)',
            'descripcion' => 'El gerente solicitó la baja: sus accesos ya se suspendieron. Regionales y RH se enteran y pueden rehabilitarlos.',
            'destinatarios' => ['regional', 'rh'],
            'fallback' => ['gerencia_rh'],
        ],
        'cierre_preautorizacion' => [
            'etiqueta' => 'Baja por preautorizar',
            'descripcion' => 'Se solicitó una baja; su superior debe preautorizarla.',
            'destinatarios' => ['aprobador'],
            'fallback' => [],
        ],
        'cierre_autorizacion_rh' => [
            'etiqueta' => 'Baja pendiente de autorización de RH',
            'descripcion' => 'La baja está preautorizada; RH autoriza.',
            'destinatarios' => ['rh'],
            'fallback' => ['gerencia_rh'],
        ],
        'pago_por_programar' => [
            'etiqueta' => 'Pago de finiquito por programar',
            'descripcion' => 'El finiquito está autorizado; se programa el pago según flujo.',
            'destinatarios' => ['usuarios_con_permiso'],
            'permiso' => 'cierres.programar_pago',
            'fallback' => ['rh'],
        ],
        'cita_finiquito' => [
            'etiqueta' => 'Excolaborador por citar (firma y pago)',
            'descripcion' => 'El pago está programado: el jefe cita al excolaborador.',
            'destinatarios' => ['jefe_directo'],
            'fallback' => ['responsable_sucursal'],
        ],
        'colaborador_alta' => [
            'etiqueta' => 'Alta de colaborador',
            'descripcion' => 'Un colaborador quedó activo: RH y Sistemas (cuenta, accesos y equipo).',
            'destinatarios' => ['rh', 'sistemas'],
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
