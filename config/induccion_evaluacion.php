<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Evaluación oficial del Programa de Inducción (FO vigente)
    |--------------------------------------------------------------------------
    |
    | Fuente: Evaluacion_Induccion_Mr_Lana 2026.pdf (docs/onboarding), tal
    | cual — incluida la CLAVE DE RESPUESTAS. Este archivo se lee SOLO desde
    | App\Services\Onboarding\EvaluacionInduccionService (servidor). Las
    | claves 'correcta'/'correcto'/'letra_correcta'/'rubrica' NUNCA deben
    | salir en un prop de Inertia, un JSON de la API móvil, ni en el PDF que
    | recibe el colaborador — ver EvaluacionInduccionService::preguntasPublicas()
    | y aArray(), que las filtran explícitamente.
    |
    | Un cambio real de formato (nueva versión del FO) debe cambiar 'version'
    | para que las evaluaciones ya calificadas conserven su snapshot.
    |
    */

    'version' => 'FO-RH-EI-2026-v1',

    'total_puntos' => 30,
    'calificacion_aprobatoria' => 24,

    // Escala de resultados (FO-RH, página 8).
    'escala' => [
        ['min' => 27, 'max' => 30, 'nivel' => 'sobresaliente'],
        ['min' => 24, 'max' => 26, 'nivel' => 'aprobado'],
        ['min' => 0, 'max' => 23, 'nivel' => 'requiere_reforzamiento'],
    ],

    // Plazo máximo para reaplicar tras reforzamiento (días HÁBILES).
    'dias_habiles_reevaluacion' => 5,

    /*
    | Sección A · Opción múltiple · 12 puntos (1 c/u).
    | 'correcta' = índice (0-based) de la opción correcta. NUNCA al cliente.
    */
    'seccion_a' => [
        ['pregunta' => 'Mr. Lana es una entidad financiera especializada en:', 'opciones' => ['Créditos hipotecarios', 'Nano y micro créditos', 'Seguros de vida', 'Tarjetas de crédito'], 'correcta' => 1],
        ['pregunta' => '¿En qué estado nace Mr. Lana?', 'opciones' => ['Puebla', 'Guerrero', 'Morelos', 'Ciudad de México'], 'correcta' => 2],
        ['pregunta' => 'De acuerdo con nuestra misión, lo que nos distingue de la competencia es:', 'opciones' => ['La tasa de interés más baja del mercado', 'La rapidez de nuestro servicio', 'El número de sucursales', 'La publicidad en redes sociales'], 'correcta' => 1],
        ['pregunta' => '¿Cuál de los siguientes NO es uno de los cuatro principios de Mr. Lana?', 'opciones' => ['Innovación', 'Diligencia', 'Competitividad', 'Integridad'], 'correcta' => 2],
        ['pregunta' => 'El tipo de liderazgo en el que todas las áreas colaboran y trabajan juntas para alcanzar objetivos comunes es el liderazgo:', 'opciones' => ['Ascendente', 'Descendente', 'Horizontal', 'Individual'], 'correcta' => 2],
        ['pregunta' => 'Para Mr. Lana, el "trabajo inteligente" consiste en:', 'opciones' => ['Trabajar más horas que los demás', 'Realizar las actividades de manera estratégica, organizada y eficiente, priorizando lo más importante', 'Hacer varias tareas a la vez sin planear', 'Esperar instrucciones antes de cada actividad'], 'correcta' => 1],
        ['pregunta' => 'La herramienta de consulta que existe en cada sucursal y explica a detalle todos los procesos es:', 'opciones' => ['El Reglamento Interior de Trabajo', 'La Carpeta Gerencial', 'El contrato individual de trabajo', 'El manual del cliente'], 'correcta' => 1],
        ['pregunta' => 'Las cinco áreas que reportan directamente a la Dirección General son:', 'opciones' => ['Dirección Comercial, Recursos Humanos, Contraloría, Jurídico Empresarial y Sistemas', 'Regionales, Sucursales, Gestores, Cobranza y Caja', 'Contabilidad, Auditoría, Ventas, Compras y Almacén', 'Recursos Humanos, Tesorería, Cobranza, Sistemas y Seguridad'], 'correcta' => 0],
        ['pregunta' => 'En la sucursal, ¿qué puesto se encarga de asignar la cartera a los gestores?', 'opciones' => ['Gestor de Crédito', 'Coordinación', 'Subgerente de Sucursal', 'Auxiliar Contable'], 'correcta' => 2],
        ['pregunta' => '¿Qué puesto realiza la dispersión y recepción de efectivo, así como el cuadre de caja?', 'opciones' => ['Coordinación', 'Gestor de Crédito', 'Regional', 'Gerente de Sucursal'], 'correcta' => 0],
        ['pregunta' => 'Las obligaciones de los trabajadores están establecidas en la Ley Federal del Trabajo, en el artículo:', 'opciones' => ['123', '134', '135', '47'], 'correcta' => 1],
        ['pregunta' => '¿Cuál es el orden correcto del ciclo de nuestro modelo de negocio?', 'opciones' => ['Entrega → Evaluación → Cobranza → Promoción', 'Promoción y prospección → Evaluación y autorización → Entrega del crédito → Seguimiento y cobranza', 'Evaluación → Promoción → Cobranza → Entrega', 'Cobranza → Entrega → Promoción → Evaluación'], 'correcta' => 1],
    ],

    /*
    | Sección B · Verdadero o falso · 8 puntos (1 c/u). 'correcto' = bool.
    */
    'seccion_b' => [
        ['afirmacion' => 'Está permitido usar tenis como parte del código de vestimenta, siempre que estén limpios.', 'correcto' => false],
        ['afirmacion' => 'Es obligatorio portar el gafete de identificación en un lugar visible durante toda la jornada laboral.', 'correcto' => true],
        ['afirmacion' => 'Otorgar préstamos propios a los clientes es una conducta de cero tolerancia.', 'correcto' => true],
        ['afirmacion' => 'Puedo vender productos dentro de la sucursal sin autorización si lo hago en mi hora de comida.', 'correcto' => false],
        ['afirmacion' => 'La Carpeta Gerencial se actualiza conforme cambian las políticas y los formatos institucionales.', 'correcto' => true],
        ['afirmacion' => 'Trabajar para otra empresa mientras se labora en Mr. Lana está permitido si no afecta mi horario.', 'correcto' => false],
        ['afirmacion' => 'La Coordinación mantiene una relación de apoyo administrativo con la Gerencia de Sucursal.', 'correcto' => true],
        ['afirmacion' => 'Asistir a los cursos de capacitación que proporciona la empresa es una obligación del colaborador.', 'correcto' => true],
    ],

    /*
    | Sección C · Relaciona columnas (Principios y cultura) · 5 puntos (1 c/u).
    | 'letra_correcta' = clave de 'descripciones'. NUNCA al cliente.
    */
    'seccion_c' => [
        'conceptos' => [
            ['concepto' => 'Innovación', 'letra_correcta' => 'C'],
            ['concepto' => 'Diligencia', 'letra_correcta' => 'E'],
            ['concepto' => 'Integridad', 'letra_correcta' => 'A'],
            ['concepto' => 'Inteligencia colectiva', 'letra_correcta' => 'D'],
            ['concepto' => 'Documentación de procesos', 'letra_correcta' => 'B'],
        ],
        'descripciones' => [
            'A' => 'Somos coherentes en nuestra forma de ser y de actuar, siempre desde lo correcto y lo justo.',
            'B' => 'Administración por procesos ordenados, estructurados y documentados.',
            'C' => 'Implementamos nuevas tecnologías y nos mantenemos siempre a la vanguardia.',
            'D' => 'La suma de ideas y conocimientos del equipo genera mejores soluciones.',
            'E' => 'Nos esforzamos día con día para atender de manera eficaz cada uno de nuestros procesos.',
        ],
    ],

    /*
    | Sección D · Casos prácticos · 5 puntos. Calificados a mano por RH según
    | la rúbrica oficial — la rúbrica NUNCA se muestra al colaborador (ver
    | EvaluacionInduccionPolicy::verRubrica()). "Se otorga el punto cuando la
    | idea es correcta, aunque el colaborador la exprese con sus palabras."
    */
    'seccion_d' => [
        [
            'clave' => 'caso_26',
            'puntos_max' => 3,
            'enunciado' => 'Un cliente entrega a tu compañero tres pagos; él aplica dos a la cuenta de ese cliente y uno a la cuenta de otro cliente. ¿Cómo se llama esta conducta, cómo la clasifica la empresa y qué debes hacer tú?',
            'rubrica' => [
                ['clave' => 'identifica_malversacion', 'puntos' => 1, 'criterio' => 'Identifica la conducta como malversación de fondos.'],
                ['clave' => 'cero_tolerancia', 'puntos' => 1, 'criterio' => 'Indica que es una conducta de cero tolerancia para la empresa.'],
                ['clave' => 'reporta', 'puntos' => 1, 'criterio' => 'Señala que no debe participar ni encubrirla y que debe reportarla de inmediato a su jefe(a) inmediato(a) o a Recursos Humanos.'],
            ],
        ],
        [
            'clave' => 'caso_27',
            'puntos_max' => 2,
            'enunciado' => 'Es tu primera semana y tienes duda sobre cómo llenar un formato oficial de uso diario. ¿Dónde lo consultas en tu sucursal y por qué es importante revisar siempre la versión vigente?',
            'rubrica' => [
                ['clave' => 'consulta_carpeta', 'puntos' => 1, 'criterio' => 'Menciona que consulta la Carpeta Gerencial de su sucursal.'],
                ['clave' => 'version_vigente', 'puntos' => 1, 'criterio' => 'Explica que la Carpeta se actualiza conforme cambian las políticas y los formatos, por lo que usar la versión vigente asegura trabajar bajo el mismo estándar y evita errores.'],
            ],
        ],
    ],

    /*
    | Sección E · Opinión sobre la inducción · NO CALIFICA. Se guarda aparte
    | para estadísticas agregadas de RH (ver sección 21 del encargo).
    */
    'seccion_e' => [
        'aspectos' => [
            ['clave' => 'claridad', 'texto' => 'La información fue clara y fácil de entender.'],
            ['clave' => 'conocimiento_puesto', 'texto' => 'Ahora conozco qué se espera de mí en mi puesto.'],
            ['clave' => 'bienvenida', 'texto' => 'Me siento bienvenido(a) y parte de Mr. Lana.'],
        ],
        'escala_min' => 1,
        'escala_max' => 5,
        // Leyenda de la escala tal cual el formato: «Marca con una "X" del 1
        // (muy en desacuerdo) al 5 (totalmente de acuerdo)».
        'escala_etiquetas' => [1 => 'Muy en desacuerdo', 5 => 'Totalmente de acuerdo'],
        // Pregunta abierta al pie de la sección E (se guarda en opinion.tema_reforzar).
        'pregunta_abierta' => ['clave' => 'tema_reforzar', 'texto' => '¿Qué tema te gustaría reforzar?'],
    ],

];
