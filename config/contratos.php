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

    // Documentos del préstamo autorizado.
    'paquete_prestamo' => ['contrato_prestamo', 'pagare'],

    /*
    | Criterios sugeridos para la evaluación de periodo de prueba. Son solo
    | una sugerencia editable por RH: la captura acepta cualquier lista de
    | criterios con calificación 0–10.
    */
    'criterios_evaluacion' => [
        'Cumplimiento de funciones del puesto',
        'Puntualidad y asistencia',
        'Trabajo en equipo',
        'Apego a políticas y procedimientos',
    ],

    // Calificación mínima (0–10) sugerida para marcar la evaluación como aprobada
    // cuando el evaluador no indica el resultado explícitamente.
    'calificacion_minima_aprobatoria' => (float) env('CONTRATOS_CALIFICACION_MINIMA', 7),

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
