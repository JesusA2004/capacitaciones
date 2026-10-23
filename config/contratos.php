<?php

use App\Enums\CategoriaDocumento;
use App\Enums\TipoContratacion;

return [

    /*
    |--------------------------------------------------------------------------
    | Ciclo contractual y motor documental
    |--------------------------------------------------------------------------
    |
    | Configuración de negocio versionada (no hardcodeada en servicios). Los
    | textos jurídicos NO viven aquí: cada clave apunta a una plantilla
    | (App\Models\DocumentTemplate::clave) que RH/Jurídico carga desde la API
    | (HTML con variables, DOCX o formato oficial PDF). Si una plantilla no
    | está cargada/activa, el documento queda como pendiente con un aviso
    | explícito — el sistema nunca inventa un texto de contrato.
    |
    */

    // Días antes del vencimiento del contrato (periodo de prueba /
    // capacitación / tiempo determinado) en que el scheduler crea la
    // evaluación, las tareas y notifica a jefe inmediato y RH.
    'dias_aviso_vencimiento' => (int) env('CONTRATOS_DIAS_AVISO_VENCIMIENTO', 15),

    /*
    | Catálogo de plantillas documentales conocidas por el flujo. clave =>
    | [nombre, categoría del expediente]. Las banderas de firma/impresión/
    | huella/testigos se configuran en cada plantilla (DocumentTemplate).
    */
    'plantillas' => [
        'contrato_periodo_prueba' => ['nombre' => 'Contrato inicial de periodo de prueba', 'categoria' => CategoriaDocumento::Contratos->value],
        'contrato_capacitacion' => ['nombre' => 'Contrato de capacitación inicial', 'categoria' => CategoriaDocumento::Contratos->value],
        'contrato_tiempo_determinado' => ['nombre' => 'Contrato por tiempo determinado', 'categoria' => CategoriaDocumento::Contratos->value],
        'contrato_confidencialidad' => ['nombre' => 'Contrato de confidencialidad', 'categoria' => CategoriaDocumento::Contratos->value],
        'contrato_no_competencia' => ['nombre' => 'Contrato de no competencia', 'categoria' => CategoriaDocumento::Contratos->value],
        'contrato_indeterminado' => ['nombre' => 'Contrato por tiempo indeterminado', 'categoria' => CategoriaDocumento::Contratos->value],
        'pagare' => ['nombre' => 'Pagaré', 'categoria' => CategoriaDocumento::Prestamos->value],
        'contrato_prestamo' => ['nombre' => 'Contrato de préstamo personal', 'categoria' => CategoriaDocumento::Prestamos->value],
        'aviso_termino' => ['nombre' => 'Aviso de término de la relación laboral', 'categoria' => CategoriaDocumento::BajaFiniquito->value],
        'finiquito' => ['nombre' => 'Finiquito', 'categoria' => CategoriaDocumento::BajaFiniquito->value],
        'comprobante_vacaciones' => ['nombre' => 'Comprobante de vacaciones', 'categoria' => CategoriaDocumento::Vacaciones->value],
        'comprobante_permiso' => ['nombre' => 'Comprobante de permiso', 'categoria' => CategoriaDocumento::Permisos->value],
        'acta_administrativa' => ['nombre' => 'Acta administrativa', 'categoria' => CategoriaDocumento::Actas->value],
        'acta_hechos' => ['nombre' => 'Acta de hechos', 'categoria' => CategoriaDocumento::Actas->value],
        'carta_responsiva' => ['nombre' => 'Carta responsiva', 'categoria' => CategoriaDocumento::Actas->value],
        'acta_auditoria' => ['nombre' => 'Acta de auditoría', 'categoria' => CategoriaDocumento::Actas->value],
    ],

    /*
    | Documentos contractuales que se preparan automáticamente al dar de
    | alta a un colaborador según su modalidad de contratación. El primer
    | elemento es el contrato principal (el que controla el estado del alta
    | "pendiente de contrato / pendiente de firma").
    */
    'paquetes_alta' => [
        TipoContratacion::PeriodoPrueba->value => ['contrato_periodo_prueba', 'contrato_confidencialidad', 'contrato_no_competencia'],
        TipoContratacion::CapacitacionInicial->value => ['contrato_capacitacion', 'contrato_confidencialidad', 'contrato_no_competencia'],
        TipoContratacion::TiempoDeterminado->value => ['contrato_tiempo_determinado', 'contrato_confidencialidad', 'contrato_no_competencia'],
        TipoContratacion::Indeterminado->value => ['contrato_indeterminado', 'contrato_confidencialidad', 'contrato_no_competencia'],
    ],

    // Paquete que se genera al renovar (evaluación aprobada con renovación).
    'paquete_renovacion' => ['contrato_indeterminado'],

    /*
    | Módulo «Contratos» (RH → Contratación y expediente): regularización de
    | los contratos que le corresponden a cada colaborador activo según su
    | fecha de ingreso (App\Services\Contratos\RegularizacionContratosService).
    |
    | - 'equivalentes_generados': un documento ya generado con otra clave
    |   cuenta como el mismo contrato (los contratos de «periodo de prueba»
    |   anteriores son la misma etapa que la capacitación inicial).
    | - 'tipos_expediente': tipo de documento del expediente (document_types.
    |   clave) que es el original FIRMADO físicamente de ese contrato (p. ej.
    |   el escaneo que se subió en la migración). Solo se muestra como
    |   información («Firmado cargado»): NO cuenta como generado y el
    |   documento del sistema se puede generar igual. «Contrato laboral» no
    |   distingue cuál contrato es; se asocia al contrato de ingreso.
    | - 'dias_alerta_periodo_prueba': «Periodos por vencer» alerta cuando el
    |   periodo de prueba vence en estos días NATURALES o menos. (El aviso
    |   automático que abre la evaluación, VencimientoContratosService, sigue
    |   con 'dias_aviso_vencimiento' en días HÁBILES — FO-RH-EPP-001.)
    */
    'regularizacion' => [
        'equivalentes_generados' => [
            'contrato_capacitacion' => ['contrato_periodo_prueba'],
            'contrato_periodo_prueba' => ['contrato_capacitacion'],
        ],
        'tipos_expediente' => [
            'contrato_capacitacion' => ['contrato'],
            'contrato_periodo_prueba' => ['contrato'],
            'contrato_confidencialidad' => ['carta_confidencialidad'],
            'contrato_no_competencia' => ['contrato_no_competencia'],
        ],
        'dias_alerta_periodo_prueba' => (int) env('CONTRATOS_DIAS_ALERTA_PERIODO_PRUEBA', 15),
    ],

    // Documentos del préstamo autorizado.
    'paquete_prestamo' => ['contrato_prestamo', 'pagare'],

    /*
    | Criterios sugeridos para la evaluación de periodo de prueba. Son solo
    | una sugerencia editable por RH: la captura acepta cualquier lista de
    | criterios con calificación 0–10.
    */
    // Son los 7 criterios del FORMATO DE EVALUACIÓN DE CAPACITACIÓN INICIAL
    // oficial (Jurídico): lo que se captura aquí se traslada a sus casillas
    // Acredita / No acredita. Un criterio distinto no aparece en el formato.
    'criterios_evaluacion' => [
        'Gestión operativa de la sucursal',
        'Cumplimiento de KPI’s',
        'Liderazgo y supervisión',
        'Reportes y controles administrativos',
        'Toma de decisiones',
        'Apego a políticas internas',
        'Responsabilidad y seguimiento',
    ],

    // Calificación mínima (0–10) sugerida para marcar la evaluación como aprobada
    // cuando el evaluador no indica el resultado explícitamente.
    // Usado SOLO por evaluaciones legacy (version null/1, formato libre);
    // nunca se aplica a la evaluación oficial (ver 'evaluacion_periodo_prueba_v2').
    'calificacion_minima_aprobatoria' => (float) env('CONTRATOS_CALIFICACION_MINIMA', 7),

    /*
    | Evaluación de Término de Periodo de Prueba — formato OFICIAL
    | (docs/onboarding/Evaluacion_Periodo_de_Prueba_Operativo.pdf, folio
    | FO-RH-EPP-001 v02 oct-2026). Toda captura NUEVA usa este formato
    | (version = 'version_vigente'); las evaluaciones ya capturadas con el
    | formato libre anterior (version null/1) NUNCA se recalculan con estas
    | reglas — ver 'criterios_evaluacion'/'calificacion_minima_aprobatoria'
    | arriba, que siguen vigentes solo para leer ese histórico.
    |
    | INCONSISTENCIA DEL PDF (documentada, no es un bug de este código): el
    | Paso 3 (pág. 2) muestra una franja visual "24 a 36 puntos ALCANZA EL
    | MÍNIMO" (deja 23 sin banda), pero el texto de la regla, inmediatamente
    | debajo, exige "Total de 25 puntos o más". Se usa el TEXTO como regla
    | canónica: 'total_minimo_renovar' = 25. Un total de 24 NO renueva.
    */
    'evaluacion_periodo_prueba_v2' => [
        'version_vigente' => 2,
        'escala_min' => 1,
        'escala_max' => 4,
        'total_maximo' => 36,
        'total_minimo_renovar' => 25,

        // 9 criterios reales (Paso 1), escala 4 Muy bueno / 3 Bueno / 2
        // Regular / 1 Deficiente. "Si escribe un 1 en cualquier renglón, el
        // resultado será NO SE RENUEVA" — sin importar el total. Cada criterio
        // trae la descripción OFICIAL de cada valor (niveles 4/3/2/1) que ve el
        // jefe evaluador en tarjetas y que se imprime en el PDF; 'captura_meta'
        // habilita los campos Meta / Logró / % del renglón "Cumple sus metas".
        'criterios' => [
            [
                'clave' => 'conoce_trabajo',
                'etiqueta' => 'Conoce y hace bien su trabajo',
                'niveles' => [
                    '4' => 'Lo hace todo solo y sin errores; puede enseñar a otros.',
                    '3' => 'Lo hace bien; pide ayuda solo en casos raros.',
                    '2' => 'Necesita que lo supervisen seguido.',
                    '1' => 'No ha aprendido lo básico del puesto.',
                ],
            ],
            [
                'clave' => 'cumple_metas',
                'etiqueta' => 'Cumple sus metas',
                'captura_meta' => true,
                'niveles' => [
                    '4' => 'Logró 100 % o más de su meta.',
                    '3' => 'Logró del 90 % al 99 %.',
                    '2' => 'Logró del 80 % al 89 %.',
                    '1' => 'Logró menos del 80 %.',
                ],
            ],
            [
                'clave' => 'sin_errores',
                'etiqueta' => 'Trabaja sin errores',
                'niveles' => [
                    '4' => 'Sin errores ni correcciones.',
                    '3' => 'Errores pequeños que él mismo corrige.',
                    '2' => 'Errores que otros tienen que corregir.',
                    '1' => 'Errores graves: quejas o pérdidas.',
                ],
            ],
            [
                'clave' => 'asistencia_puntualidad',
                'etiqueta' => 'Asistencia y puntualidad',
                'descripcion' => 'Use las faltas y retardos que anotó RH arriba.',
                'niveles' => [
                    '4' => '0 faltas y máximo 1 retardo.',
                    '3' => '0 faltas y 2 a 3 retardos.',
                    '2' => '1 falta o 4 a 5 retardos.',
                    '1' => '2 o más faltas o 6 o más retardos.',
                ],
            ],
            [
                'clave' => 'cumplimiento_politicas',
                'etiqueta' => 'Cumplimiento a políticas y procesos operativos',
                'descripcion' => 'Prospección, colocación, cobranza, manejo de efectivo, expedientes, etc.',
                'niveles' => [
                    '4' => 'Cumple todas las políticas y procesos; sin observaciones.',
                    '3' => 'Una observación menor que corrigió de inmediato.',
                    '2' => 'Dos o más observaciones o una llamada de atención.',
                    '1' => 'Incumple de forma repetida o tiene acta administrativa.',
                ],
            ],
            [
                'clave' => 'imagen_uniforme',
                'etiqueta' => 'Uniforme, imagen y reglas internas',
                'niveles' => [
                    '4' => 'Siempre cumple, sin que se le recuerde.',
                    '3' => 'Un solo recordatorio en el periodo.',
                    '2' => 'Dos o tres recordatorios.',
                    '1' => 'No cumple pese a los recordatorios.',
                ],
            ],
            [
                'clave' => 'trato_cliente',
                'etiqueta' => 'Trato al cliente',
                'niveles' => [
                    '4' => 'Amable, resuelve; cero quejas.',
                    '3' => 'Amable y correcto; sin quejas.',
                    '2' => 'A veces descuida el trato; 1 queja.',
                    '1' => 'Trato inadecuado; 2 o más quejas.',
                ],
            ],
            [
                'clave' => 'trabajo_equipo',
                'etiqueta' => 'Trabajo en equipo y actitud',
                'niveles' => [
                    '4' => 'Apoya sin que se lo pidan; acepta indicaciones.',
                    '3' => 'Colabora y sigue indicaciones.',
                    '2' => 'Hay que repetirle o se resiste a veces.',
                    '1' => 'Genera conflictos o no sigue indicaciones.',
                ],
            ],
            [
                'clave' => 'aprendizaje_iniciativa',
                'etiqueta' => 'Aprende e iniciativa',
                'niveles' => [
                    '4' => 'Aprendió rápido y propone mejoras.',
                    '3' => 'Aprendió en el tiempo esperado.',
                    '2' => 'Aprende lento; depende de otros.',
                    '1' => 'No logra aprender las tareas.',
                ],
            ],
        ],

        // Preguntas filtro (Paso 2), Sí/No. "Un solo SÍ significa NO SE
        // RENUEVA" — independiente del total y de los criterios.
        'filtros' => [
            ['clave' => 'faltante_valores', 'etiqueta' => 'Faltantes de dinero, valores o mercancía no aclarados'],
            ['clave' => 'documentos_falsificados', 'etiqueta' => 'Alteró o falsificó documentos, firmas o expedientes'],
            ['clave' => 'conducta_grave', 'etiqueta' => 'Robo, agresión, acoso, o bajo efectos de alcohol/drogas'],
            ['clave' => 'informacion_no_autorizada', 'etiqueta' => 'Compartió información de clientes/empresa sin autorización'],
            ['clave' => 'riesgo_seguridad', 'etiqueta' => 'Puso en riesgo personas o bienes por no seguir reglas de seguridad'],
        ],
    ],

    /*
    | Cierre laboral: tipos de baja habilitados (App\Enums\TipoBaja) y si la
    | baja exige finiquito firmado y pago confirmado antes de ejecutarse.
    */
    'cierre' => [
        'requiere_finiquito_firmado' => true,
        'requiere_pago_confirmado' => true,
    ],

    // Tamaño máximo (MB) de anexos/escaneos/comprobantes del flujo documental.
    'max_upload_mb' => (int) env('DOCUMENTOS_LABORALES_MAX_UPLOAD_MB', 20),
    'extensiones_permitidas' => ['pdf', 'jpg', 'jpeg', 'png'],

];
