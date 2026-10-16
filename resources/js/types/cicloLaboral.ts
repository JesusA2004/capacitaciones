/**
 * Contrato del DTO de App\Services\CicloLaboral\CicloLaboralService::obtenerEstado()
 * — la misma forma la consumen la web (Inertia) y la app móvil (API v1).
 * Ninguna pantalla calcula el estado de la persona por su cuenta.
 */

export type AccionCiclo = {
    clave: string;
    etiqueta: string;
    tipo: 'primaria' | 'secundaria' | 'peligro' | string;
};

export type PasoCiclo = {
    clave: string;
    etiqueta: string;
    estado: 'completado' | 'actual' | 'pendiente' | 'detenido' | string;
};

export type ItemTimeline = {
    fecha: string | null;
    titulo: string;
    descripcion: string | null;
    actor: string | null;
    etapa: string;
    evento: string;
};

export type AprobacionItem = {
    id: number;
    proceso: string;
    etapa: 'preautorizacion' | 'autorizacion_rh' | string;
    etapa_etiqueta: string;
    ronda: number;
    estado:
        | 'pendiente'
        | 'aprobado'
        | 'rechazado'
        | 'devuelto'
        | 'omitido'
        | 'cancelado'
        | string;
    estado_etiqueta: string;
    decision: string | null;
    comentario: string | null;
    aprobador: string | null;
    decidido_por: string | null;
    decidido_por_puesto: string | null;
    decidido_en: string | null;
    creada_en: string | null;
};

export type ResumenAprobaciones = {
    proceso: string;
    ronda: number;
    preautorizacion: AprobacionItem | null;
    autorizacion_rh: AprobacionItem | null;
    pendiente: {
        etapa: string;
        etapa_etiqueta: string;
        aprobador: string | null;
    } | null;
    autorizado_rh: boolean;
    historial: AprobacionItem[];
};

/**
 * Evaluación de Término de Periodo de Prueba — DTO de
 * EvaluacionPeriodoPruebaService::aArray(). `version` null/1 = formato
 * libre legacy (criterios: {criterio, calificacion 0-10}); `version` >= 2
 * ("es_oficial") = formato oficial FO-RH-EPP-001 (criterios: {clave,
 * etiqueta, calificacion 1-4}, filtros: {clave, etiqueta, respuesta}).
 */
export type EvaluacionPeriodoPruebaResumen = {
    id: number;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
        jefe: string | null;
        fecha_ingreso: string | null;
    };
    contrato: Record<string, unknown>;
    estado: string;
    estado_etiqueta: string;
    fecha_limite: string | null;
    fecha_evaluacion: string | null;
    version: number | null;
    es_oficial: boolean;
    criterios:
        | {
              criterio: string;
              calificacion: number;
              comentario?: string | null;
              acredita?: boolean;
          }[]
        | { clave: string; etiqueta: string; calificacion: number }[];
    filtros: { clave: string; etiqueta: string; respuesta: boolean }[];
    criterios_oficiales: { clave: string; etiqueta: string }[];
    filtros_oficiales: { clave: string; etiqueta: string }[];
    total_minimo_renovar: number;
    total_maximo: number;
    criterios_sugeridos: string[];
    calificacion: string | number | null;
    resultado: string | null;
    recomienda_renovar: boolean | null;
    observaciones: string | null;
    decision_renovar: boolean | null;
    comentario_autorizacion: string | null;
    autorizada_en: string | null;
    contrato_renovacion_id: number | null;
    excepcion: {
        id: number;
        estado: 'pendiente' | 'aprobada' | 'rechazada' | string;
        estado_etiqueta: string;
        motivo: string;
        comentario_resolucion: string | null;
        resuelto_en: string | null;
    } | null;
};

export type EstadoCiclo = {
    persona: {
        tipo: 'candidato' | 'colaborador';
        id: number;
        candidato_id: number | null;
        colaborador_id: number | null;
        nombre: string;
        puesto: string | null;
        sucursal: string | null;
        numero_empleado?: string | null;
        jefe?: string | null;
        estatus?: string;
        fecha_ingreso?: string | null;
        fuente?: string | null;
    };
    etapa: { clave: string; etiqueta: string; numero: number | null };
    estado: { clave: string; etiqueta: string };
    progreso: number;
    responsable_actual: { rol: string; nombre: string | null };
    siguiente_accion: { clave: string; etiqueta: string } | null;
    fecha_desde_estado: string | null;
    bloqueos: string[];
    pasos: PasoCiclo[];
    aprobaciones: ResumenAprobaciones | null;
    timeline: ItemTimeline[];
    acciones_permitidas: AccionCiclo[];
    cierre_id?: number | null;
    reingreso_id?: number | null;
    onboarding_id?: number | null;
    evaluacion_id?: number | null;
    contrato_id?: number | null;
};

export type EvidenciaCandidato = {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    nombre: string;
    mime: string | null;
    tamano: number | null;
    subida_en: string | null;
};

/** Mini expediente previo a la contratación, agrupado como en el NAS. */
export type ExpedienteCandidato = {
    cv: {
        nombre: string;
        tamano: number | null;
        subido_en: string | null;
    } | null;
    grupos: {
        clave: string;
        titulo: string;
        etapa: 'socioeconomico' | 'psicometricas' | 'referencias' | 'otros';
        archivos: EvidenciaCandidato[];
    }[];
    total: number;
};

export type AccesosRapidosCandidato = {
    psicometrico: EvidenciaCandidato | null;
    socioeconomico: EvidenciaCandidato | null;
    video: EvidenciaCandidato | null;
    tiene_cv: boolean;
};

export type CandidatoFicha = {
    id: number;
    nombre: string;
    apellidos: string | null;
    nombre_completo: string;
    telefono: string | null;
    correo: string | null;
    fuente: string | null;
    fuente_etiqueta: string | null;
    campana: string | null;
    campana_reclutamiento_id: number | null;
    empresa: string | null;
    sucursal: string | null;
    sucursal_id: number | null;
    departamento: string | null;
    puesto: string | null;
    puesto_objetivo_id: number | null;
    vacante_id: number | null;
    /** Sin vacante ligada todavía: pipeline general, no puede avanzar a contratación (CLAUDE.md §3). */
    espontaneo: boolean;
    empresa_id: number | null;
    departamento_id: number | null;
    responsable_rh_id: number | null;
    gerente_involucrado_id: number | null;
    responsable_rh: string | null;
    gerente: string | null;
    observaciones: string | null;
    estado: string;
    estado_etiqueta: string;
    /** Columna canónica del tablero (CLAUDE.md §4) — nunca el sub-estado técnico. */
    fase: string;
    motivo_salida: string | null;
    /** «Categoría · Motivo» del catálogo, si ya salió del proceso. */
    motivo_rechazo: string | null;
    salida_en: string | null;
    /** Requisitos indispensables preguntados al registrarlo. */
    respuestas_filtro: {
        pregunta: string;
        respuesta: boolean;
        cumple: boolean;
    }[];
    tiene_cv: boolean;
    expediente: ExpedienteCandidato;
    colaborador_id: number | null;
    creado_en: string | null;
    contratado_en: string | null;
    entrevistas: {
        id: number;
        realizada_en: string;
        entrevistador: string | null;
        resultado: string;
        resultado_etiqueta: string;
        observaciones: string | null;
    }[];
    psicometricas: {
        id: number;
        link: string | null;
        enviada_en: string | null;
        resultados_en: string | null;
        resumen_resultados: string | null;
        revision_resultado: string | null;
        revision_observaciones: string | null;
        revisada_en: string | null;
        evidencias: EvidenciaCandidato[];
    }[];
    socioeconomicos: {
        id: number;
        fecha_visita: string;
        visitador: string | null;
        direccion: string;
        checklist: Record<string, boolean | number | string | null>;
        riesgos: string | null;
        observaciones: string | null;
        /** null = cargado, pendiente de revisión del responsable. */
        resultado: string | null;
        resultado_etiqueta: string;
        revisado_en?: string | null;
        revision_observaciones?: string | null;
        evidencias: EvidenciaCandidato[];
    }[];
    /** Ver psicométrico / socioeconómico / video / CV (CandidatoPresenter::accesosRapidos). */
    accesos_rapidos?: AccesosRapidosCandidato;
    referencias: {
        id: number;
        empresa: string;
        contacto: string;
        telefono: string | null;
        relacion_puesto: string | null;
        resultado: string;
        resultado_etiqueta: string;
        observaciones: string | null;
        fecha_validacion: string;
        validada_por: string | null;
        evidencias: EvidenciaCandidato[];
    }[];
    invitacion: {
        id: number;
        estado: string;
        estado_etiqueta: string;
        expira_en: string;
        usada_en: string | null;
    } | null;
    /** Excepción jerárquica sobre un rechazo de RH (CLAUDE.md §10-12), historial completo. */
    intervenciones: {
        id: number;
        ruta: string;
        ruta_etiqueta: string;
        estado: string;
        estado_etiqueta: string;
        rechazo_rh_por: string | null;
        rechazo_rh_motivo: string | null;
        rechazo_rh_en: string | null;
        gerente_solicitante: string | null;
        motivo_solicitud: string;
        solicitada_en: string;
        aprobador: string | null;
        comentario_decision: string | null;
        decidida_en: string | null;
    }[];
};

export type ModuloOnboarding = {
    avance_id: number;
    modulo_id: number;
    titulo: string;
    descripcion: string | null;
    tipo: 'institucional' | 'puesto' | string;
    tipo_etiqueta: string;
    orden: number;
    obligatorio: boolean;
    estado:
        | 'bloqueado'
        | 'disponible'
        | 'requiere_refuerzo'
        | 'reevaluacion_habilitada'
        | 'aprobado'
        | string;
    estado_etiqueta: string;
    calificacion_minima: number;
    ultima_calificacion: number | null;
    mejor_calificacion: number | null;
    intentos: {
        numero: number;
        calificacion: number;
        aprobado: boolean;
        fecha: string | null;
        retroalimentacion_previa: string | null;
    }[];
    retroalimentacion: string | null;
    retroalimentado_en: string | null;
    contenido_url: string | null;
    contenido: string | null;
    preguntas: { indice: number; pregunta: string; opciones: string[] }[];
    puede_presentar: boolean;
    puede_retroalimentar: boolean;
};

export type OnboardingDetalle = {
    id: number;
    colaborador_id: number;
    estado: string;
    estado_etiqueta: string;
    iniciado_en: string;
    completado_en: string | null;
    checklist: { clave: string; etiqueta: string; completado: boolean }[];
    modulos: ModuloOnboarding[];
    activos: {
        tipo_activo_id: number;
        nombre: string;
        requiere_identificador: boolean;
        entregado: boolean;
        entrega: {
            id: number;
            identificador: string | null;
            entregado_en: string;
            entregado_por: string | null;
            estado: string;
            responsiva_documento_id: number | null;
            responsiva_estado: string | null;
        } | null;
    }[];
    bloqueos: string[];
    acciones: { entregar_activos: boolean; completar: boolean };
};

export type TareaBandeja = {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    titulo: string;
    descripcion: string | null;
    prioridad: string;
    prioridad_etiqueta: string;
    accion: string | null;
    etapa: string | null;
    etapa_etiqueta: string | null;
    related_type: string | null;
    related_id: number | null;
    candidato: { id: number; nombre: string } | null;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
    } | null;
    sucursal: string | null;
    antiguedad_dias: number;
    vencida: boolean;
    vence_en: string | null;
    read_at: string | null;
    resolved_at: string | null;
    creada_en: string | null;
    datos: Record<string, unknown> | null;
};

/** Inicio operativo: qué necesita atención + 4 cifras para decidir. */
export type InicioOperativoDatos = {
    nombre: string;
    pendientes: number;
    vencidos: number;
    indicadores: {
        vacantes: number;
        en_proceso: number;
        por_vencer: number;
        expedientes_incompletos: number | null;
    };
};

export type TableroRhDatos = {
    summary: {
        plantilla_activa: {
            valor: number;
            autorizada: number;
            porcentaje: number;
        };
        vacantes_abiertas: {
            valor: number;
            sucursales: number;
            corporativo: number;
        };
        rotacion_mes: {
            // null = sin periodo de rotación cargado (Excel de RH) para el mes
            // y el alcance seleccionados → la UI muestra «Sin datos cargados».
            porcentaje: number | null;
            bajas: number;
            plantilla_final: number;
        };
        costo_por_contratacion: {
            valor: number | null;
            inversion: number;
            contratados: number;
        };
        tiempo_contratacion: { dias: number | null; contratados: number };
        permanencia_promedio: { meses: number | null; colaboradores: number };
        contratos_por_vencer: { valor: number; dias: number };
        inversion_campanas_mes: { valor: number; campanas: number };
    };
    recruitment_funnel: { clave: string; etiqueta: string; total: number }[];
    time_to_hire_by_level: {
        clave: string;
        etiqueta: string;
        dias: number | null;
        contratados: number;
    }[];
    turnover_monthly: {
        mes: string;
        etiqueta: string;
        sucursales: number;
        bajas: number;
        plantilla_final: number;
        // null = mes sin periodo cargado → hueco en la gráfica, nunca 0%.
        indice: number | null;
    }[];
    headcount_by_branch: {
        sucursal_id: number;
        sucursal: string;
        plantilla_autorizada: number;
        plantilla_actual: number;
        vacantes: number;
        cumplimiento: number;
    }[];
    filters: {
        mes: string;
        periodo_etiqueta: string;
        sucursal_id: number | null;
        empresa_id: number | null;
        meses: number;
        sucursales: { id: number; nombre: string; empresa_id: number | null }[];
        empresas: { id: number; nombre: string }[];
    };
    generated_at: string;
};

/**
 * Valor de un formulario dinámico de acción del ciclo (los campos dependen
 * de la acción elegida). Tipo cerrado para que useForm no tenga que inferir
 * el FormDataConvertible recursivo de Inertia.
 */
export type CampoFormularioAccion =
    | string
    | number
    | boolean
    | null
    | File
    | File[]
    | number[]
    | Record<string, string | number | boolean | null>
    | Record<string, string | number | boolean | null>[];

export type FormularioAccion = Record<string, CampoFormularioAccion>;

/**
 * "Mi espacio" del colaborador (CicloLaboralService::misPendientes): solo
 * lo que le toca hacer o esperar, en lenguaje llano. Nunca expone los
 * nombres internos de las etapas (contratación, onboarding, periodo de
 * prueba): RH le va dando cada paso.
 */
export type PendientePersonal = {
    clave: string;
    titulo: string;
    descripcion: string;
    tipo: 'accion' | 'espera';
    accion: { etiqueta: string; href: string } | null;
    detalle: string[];
};

export type LeccionBienvenida = {
    avance_id: number;
    titulo: string;
    descripcion: string | null;
    estado: 'bloqueada' | 'disponible' | 'en_espera' | 'aprobada';
    contenido_url: string | null;
    contenido: string | null;
    preguntas: { indice: number; pregunta: string; opciones: string[] }[];
    puede_presentar: boolean;
    retroalimentacion: string | null;
    calificacion: number | null;
    calificacion_minima: number;
};

/**
 * Evaluación oficial de inducción (FO-RH-EI, 30 puntos). DTO de
 * EvaluacionInduccionService::aArray() — ver resources/js/types/induccion.ts
 * para el resto (contenido público, intentos, casos prácticos).
 */
export type EvaluacionInduccionResumen = {
    id: number;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
        fecha_ingreso: string | null;
        jefe_inmediato: string | null;
    };
    estado:
        | 'disponible'
        | 'pendiente_calificacion_rh'
        | 'requiere_reforzamiento'
        | 'reevaluacion_habilitada'
        | 'aprobada';
    estado_etiqueta: string;
    version: string;
    intentos_count: number;
    mejor_calificacion: number | null;
    fecha_limite_reevaluacion: string | null;
    retroalimentacion: string | null;
    puede_presentar: boolean;
    puede_calificar: boolean;
    intentos: {
        id: number;
        numero: number;
        presentado_en: string;
        pendiente_calificacion_rh: boolean;
        calificacion_total: number | null;
        nivel: string | null;
        nivel_etiqueta: string | null;
        aprobado: boolean | null;
        calificado_en: string | null;
    }[];
    ultimo_intento_id: number | null;
};

/**
 * Resumen del avance de contenido (capítulos) de la Inducción Mr. Lana — ver
 * EvaluacionInduccionService::resumenContenido().
 */
export type InduccionContenidoResumen = {
    avance_id: number;
    titulo: string;
    /** Avance por páginas del PDF oficial (visor). */
    total_paginas: number;
    paginas_vistas: number[];
    ultima_pagina: number | null;
    /** Capítulos: solo compatibilidad con versiones anteriores de la app. */
    total_capitulos: number;
    capitulos_vistos: number;
    /** Claves exactas de los capítulos vistos (se puede saltar entre capítulos). */
    claves_vistas?: string[];
    porcentaje: number;
    ultima_capitulo: string | null;
    completado: boolean;
    /** Fecha ISO en que RH se la presentó en una sesión (o null). */
    presentada_por_rh_en?: string | null;
};

/** Asistente de una sesión de presentación de inducción. */
export type AsistenteSesionInduccion = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    resultado: string | null;
    resultado_etiqueta: string | null;
    evaluacion_induccion_id: number | null;
};

/** SesionInduccionService::aArray(). */
export type SesionInduccion = {
    id: number;
    estado: 'en_curso' | 'presentada' | 'cancelada';
    estado_etiqueta: string;
    presentada_por: string;
    notas: string | null;
    iniciada_en: string;
    presentada_en: string | null;
    asistentes: AsistenteSesionInduccion[];
};

export type EtapaSeguimientoInduccion =
    | 'pendiente_presentar'
    | 'por_contestar'
    | 'por_calificar'
    | 'reforzamiento'
    | 'reevaluacion_por_contestar'
    | 'aprobada';

/** SesionInduccionService::seguimiento(): una fila por persona en ingreso. */
export type SeguimientoInduccion = {
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        sucursal: string | null;
    };
    etapa: EtapaSeguimientoInduccion;
    etapa_etiqueta: string;
    presentada_por_rh_en: string | null;
    evaluacion_id: number | null;
    intento_por_calificar_id: number | null;
    ultimo_intento_id: number | null;
    calificacion: number | null;
    fecha_limite_reevaluacion: string | null;
    onboarding_estado: string;
    laborando: boolean;
    /** Solo en filas con la inducción aprobada. */
    descriptivo_puesto: ResumenDescriptivoPuestoRh | null;
};

/** AcuseDescriptivoPuestoService::aArray() (sin contenido). */
export type AcuseDescriptivoPuesto = {
    id: number;
    colaborador_id: number;
    estado: 'enviado' | 'leido' | 'enterado';
    estado_etiqueta: string;
    enviado_por: string;
    enviado_en: string;
    leido_en: string | null;
    acusado_en: string | null;
    enterado: boolean;
    comentario: string | null;
    descriptivo: { id: number; nombre_puesto: string; version: number };
};

/** AcuseDescriptivoPuestoService::resumenRh(): «Enviar descriptivo de puesto». */
export type ResumenDescriptivoPuestoRh = {
    acuse: AcuseDescriptivoPuesto | null;
    puede_enviar: boolean;
    motivo: string | null;
};

/** Lado de RH de «Presentar inducción» (SesionInduccionService::datosPantalla()). */
export type SesionInduccionPantalla = {
    sesion_en_curso: SesionInduccion | null;
    recientes: SesionInduccion[];
    seguimiento: SeguimientoInduccion[];
};

/** EvaluacionInduccionService::detalleRevision() — solo RH. */
export type RevisionInduccion = {
    seccion_a: {
        numero: number;
        pregunta: string;
        opciones: string[];
        elegida: number | null;
        correcta: number;
        acierto: boolean;
    }[];
    seccion_b: {
        numero: number;
        afirmacion: string;
        elegida: boolean | null;
        correcto: boolean;
        acierto: boolean;
    }[];
    seccion_c: {
        descripciones: Record<string, string>;
        conceptos: {
            numero: number;
            concepto: string;
            elegida: string | null;
            correcta: string;
            acierto: boolean;
        }[];
    };
    seccion_d: {
        numero: number;
        clave: string;
        enunciado: string;
        puntos_max: number;
        respuesta: string;
        rubrica: {
            clave: string;
            puntos: number;
            criterio: string;
            otorgado: boolean | null;
        }[];
        puntos_obtenidos: number | null;
    }[];
    opinion: {
        claridad?: number | null;
        conocimiento_puesto?: number | null;
        bienvenida?: number | null;
        tema_reforzar?: string | null;
    } | null;
    comentario_rh: string | null;
    puntos: {
        a: number;
        b: number;
        c: number;
        d: number | null;
        total: number | null;
    };
    nivel: string | null;
    nivel_etiqueta: string | null;
    aprobado: boolean | null;
    calificado: boolean;
};

export type MisPendientes = {
    pendientes: PendientePersonal[];
    lecciones: LeccionBienvenida[];
    documentos: {
        requeridos: number;
        aprobados: number;
        faltantes: number;
    } | null;
    todo_listo: boolean;
    evaluacionInduccion?: EvaluacionInduccionResumen | null;
    induccionContenido?: InduccionContenidoResumen | null;
};

/** Límites del mini expediente del candidato (config/reclutamiento.php). */
export type LimitesArchivosCandidato = {
    max_documento_mb: number;
    max_video_mb: number;
    max_cv_mb: number;
};
