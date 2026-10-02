<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ciclo de vida laboral (cierre definitivo)
    |--------------------------------------------------------------------------
    |
    | Configuración de negocio versionada del recorrido:
    | reclutamiento → contratación → onboarding → periodo de prueba →
    | cierre / reingreso. Ver docs/CICLO_LABORAL_FINAL_IMPLEMENTADO.md.
    |
    | Los textos jurídicos NO viven aquí: cada clave de documento apunta a una
    | plantilla (DocumentTemplate::clave) que RH/Jurídico carga. Si falta la
    | plantilla, el sistema crea un bloqueo/tarea explícita y nunca inventa
    | un documento.
    |
    */

    'onboarding' => [
        // Calificación mínima aprobatoria por defecto de un módulo (0–10).
        'calificacion_minima' => (float) env('ONBOARDING_CALIFICACION_MINIMA', 8),
        // Si es false, un colaborador con contratos firmados se activa sin
        // pasar por onboarding (solo para instalaciones sin módulos).
        'obligatorio' => (bool) env('ONBOARDING_OBLIGATORIO', true),
    ],

    'periodo_prueba' => [
        // Duración por defecto cuando el puesto no tiene meses_periodo_prueba.
        'meses_por_defecto' => (int) env('PERIODO_PRUEBA_MESES_DEFECTO', 3),
        // Documentos que se generan al autorizar RH una NO renovación.
        'documentos_no_renovacion' => ['aviso_no_renovacion', 'evaluacion_periodo_prueba'],
        // Duración del contrato de capacitación/inducción (periodo de prueba)
        // por puesto. Se carga en puestos.meses_periodo_prueba (editable en
        // el catálogo); aquí solo el valor inicial del seeder. Los puestos
        // que no aparecen usan meses_por_defecto.
        'meses_por_puesto' => [
            'Gestor' => 2,
            'Gestor Volante' => 2,
            'Gestor grupal' => 2,
            'Subgerente' => 3,
            'Gerente de Sucursal' => 3,
            'Coordinadora de Sucursal' => 3,
            'Coordinadora Regional' => 3,
            'Gerente Regional Q1' => 3,
            'Gerente Regional Q3' => 3,
            'Gerente regional' => 3,
        ],
        // Aviso de "ya se debe renovar el contrato": se manda
        // contratos.dias_aviso_vencimiento días antes del fin (15 por
        // defecto → un gestor con contrato de 2 meses se avisa al mes y 15
        // días) al evaluador y, por la regla de notificación
        // "contrato_por_vencer", SIEMPRE a la gerencia de SU sucursal, al
        // gerente regional de SU región y a la Gerencia de RH (ver
        // 'organizacion' abajo y config/configuracion_sistema.php).
    ],

    // Puestos (por nombre del catálogo) que representan a la gerencia de una
    // sucursal y a la Gerencia de RH para los destinatarios dinámicos de
    // notificación. El regional se resuelve por la matriz comercial (región
    // ligada a su puesto) y organigrama.puestos_de_region.
    'organizacion' => [
        'puestos_gerencia_sucursal' => ['Gerente de Sucursal'],
        'puestos_gerencia_rh' => ['Gerencia de Recursos Humanos'],
    ],

    'cierre' => [
        // Documentos que se generan al autorizar RH un cierre, según la causa.
        'documentos_por_causa' => [
            'renuncia' => ['carta_renuncia'],
            'no_renovacion' => ['aviso_no_renovacion'],
            'bajo_desempeno' => ['aviso_termino'],
            'baja_inmediata' => ['aviso_rescision'],
        ],
        // Causas que puede elegir un jefe/gerente al solicitar una baja.
        'causas_solicitables' => [
            'renuncia',
            'no_renovacion',
            'bajo_desempeno',
            'baja_inmediata',
        ],
    ],

    'reingreso' => [
        // Paquete de contratos que se genera al reingresar (expediente completo).
        'paquete_contratos' => ['contrato_periodo_prueba', 'contrato_confidencialidad', 'contrato_no_competencia'],
        // Si el reingreso vuelve a pasar por inducción institucional además
        // de la del puesto.
        'repite_induccion_institucional' => (bool) env('REINGRESO_REPITE_INDUCCION_INSTITUCIONAL', true),
    ],

    /*
    | Catálogo de plantillas adicionales del ciclo (las de contratos ya están
    | en config/contratos.php). clave => nombre legible.
    */
    'plantillas' => [
        'aviso_no_renovacion' => 'Aviso de no renovación de contrato',
        'evaluacion_periodo_prueba' => 'Evaluación del periodo de prueba',
        'carta_renuncia' => 'Carta de renuncia voluntaria',
        'aviso_rescision' => 'Aviso de rescisión',
        'aviso_termino' => 'Aviso de término de la relación laboral',
        'carta_responsiva' => 'Carta responsiva de activo',
    ],

];
