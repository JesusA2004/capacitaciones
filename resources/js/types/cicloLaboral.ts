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
    salida_en: string | null;
    tiene_cv: boolean;
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
        resultado: string;
        resultado_etiqueta: string;
        evidencias: EvidenciaCandidato[];
    }[];
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
            porcentaje: number;
            bajas: number;
            plantilla_promedio: number;
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
        bajas: number;
        plantilla_promedio: number;
        porcentaje: number;
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

export type MisPendientes = {
    pendientes: PendientePersonal[];
    lecciones: LeccionBienvenida[];
    documentos: {
        requeridos: number;
        aprobados: number;
        faltantes: number;
    } | null;
    todo_listo: boolean;
};
