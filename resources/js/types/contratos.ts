import type { DatoFaltante } from './documentosProceso';

// ───────── Módulo «Contratos» (RegularizacionContratosService) ─────────

export type EstadoContratoCorrespondiente =
    | 'generado'
    | 'por_generar'
    | 'faltan_datos'
    | 'formato_no_listo'
    | 'pendiente_evaluacion'
    | 'completo'
    | 'no_aplica';

export type DocumentoCorrespondiente = {
    clave: string;
    nombre: string;
    estado: EstadoContratoCorrespondiente;
    estado_etiqueta: string;
    documento_id: number | null;
    descargar_url: string | null;
    faltantes: DatoFaltante[];
    motivo: string | null;
    /** Original firmado físicamente cargado en el expediente (solo informativo). */
    firmado_cargado: {
        etiqueta: string;
        /** null: quien mira no puede abrir archivos del expediente. */
        url: string | null;
        nombre: string;
        mime: string | null;
    } | null;
    /** Tipo del expediente donde se sube su firmado (null: no admite carga). */
    tipo_firmado?: string | null;
    /** Quien mira puede subir el contrato firmado de este documento. */
    puede_subir_firmado?: boolean;
};

export type EtapaContrato = {
    etapa: 'inicial' | 'indeterminado';
    etiqueta: string;
    tipo: string;
    tipo_etiqueta: string;
    contrato_id: number | null;
    estado_contrato: string | null;
    fecha_inicio: string;
    fecha_fin: string | null;
    documentos: DocumentoCorrespondiente[];
    pendiente_evaluacion: boolean;
};

export type PlanContratos = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    fecha_ingreso: string | null;
    meses_periodo_prueba: number | null;
    fin_periodo_prueba: string | null;
    periodo_vencido: boolean;
    expediente_url: string;
    ciclo_url: string;
    estado: EstadoContratoCorrespondiente;
    estado_etiqueta: string;
    motivo: string | null;
    contratos: EtapaContrato[];
    faltantes: DatoFaltante[];
    bloqueos: {
        etiqueta: string;
        detalle: string;
        configurar_url: string | null;
    }[];
    generable: boolean;
    /** Quien mira puede subir contratos firmados de esta persona. */
    puede_subir_firmado?: boolean;
    /** Foto oficial del expediente (URL protegida); null → iniciales. */
    foto_url?: string | null;
};

export type ResultadoContratosColaborador = {
    colaborador_id: number;
    nombre: string;
    resultado: 'generado' | 'sin_cambios' | 'omitido' | 'error';
    resultado_etiqueta: string;
    motivo: string | null;
    documentos: string[];
};

export type LoteContratos = {
    id: number;
    estado: 'en_cola' | 'procesando' | 'completado' | 'fallido';
    estado_etiqueta: string;
    en_curso: boolean;
    total: number;
    procesados: number;
    generados: number;
    sin_cambios: number;
    omitidos: number;
    errores: number;
    resultados: ResultadoContratosColaborador[];
    error: string | null;
    iniciado_por: string | null;
    creado_en: string | null;
    terminado_en: string | null;
};

// ───────── «Periodos por vencer» (PeriodosPruebaService) ─────────

export type PersonaPeriodoBase = {
    id: number;
    nombre: string;
    /** Foto oficial del expediente (URL protegida); null → iniciales. */
    foto_url?: string | null;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    jefe: string | null;
    fecha_ingreso: string | null;
    meses_periodo_prueba: number | null;
    ciclo_url: string;
};

export type PersonaPeriodoPrueba = PersonaPeriodoBase & {
    /** Jefe directo del organigrama (o su gerente): recibirá la evaluación. */
    evaluador: string | null;
    fin_periodo: string;
    fuente: 'contrato' | 'fecha_ingreso';
    fuente_etiqueta: string;
    dias_restantes: number;
    dias_transcurridos: number;
    alerta: boolean;
    tiene_contrato: boolean;
    contrato_generado: boolean;
    fin_contrato: string | null;
    puede_asignar: boolean;
    motivo_no_asignable: string | null;
    evaluacion: {
        id: number;
        estado: string;
        estado_etiqueta: string;
    } | null;
};

// ───────── «Evaluaciones de periodo de prueba» (tablero único) ─────────

export type PestanaEvaluacionesPrueba = 'proximas' | 'en_proceso' | 'evaluadas';

export type EstadoEvaluacionPrueba =
    'pendiente' | 'capturada' | 'devuelta' | 'autorizada';

/** Respuestas tal como se guardaron (EvaluacionPeriodoPruebaService::respuestas). */
export type RespuestasEvaluacionPrueba = {
    es_oficial: boolean;
    criterios: {
        clave: string | null;
        etiqueta: string;
        calificacion: number | null;
        comentario: string | null;
    }[];
    filtros: { clave: string | null; etiqueta: string; respuesta: boolean }[];
    escala_max: number;
    total: number | null;
    total_maximo: number | null;
    total_minimo_renovar: number | null;
    motivos_no_renueva: string[];
    recomienda_renovar: boolean | null;
    resultado: string | null;
    resultado_etiqueta: string | null;
    observaciones: string | null;
    evaluador: string | null;
    capturada_por: string | null;
    capturada_en: string | null;
    fecha_evaluacion: string | null;
    fecha_limite: string | null;
    decision: {
        renovar: boolean | null;
        comentario: string | null;
        autorizada_por: string | null;
        autorizada_en: string | null;
    };
};

export type FilaEvaluacionPrueba = {
    id: number;
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        foto_url?: string | null;
    };
    puesto: string | null;
    sucursal: string | null;
    evaluador: string | null;
    estado: EstadoEvaluacionPrueba;
    estado_etiqueta: string;
    fecha_limite: string | null;
    dias_restantes: number | null;
    calificacion: string | number | null;
    resultado: string | null;
    recomienda_renovar: boolean | null;
    decision_renovar: boolean | null;
    capturada_en: string | null;
    contrato_renovacion_id: number | null;
    documento_renovacion: { id: number; nombre: string; url: string } | null;
    puede_capturar: boolean;
    puede_decidir: boolean;
    respuestas: RespuestasEvaluacionPrueba | null;
    pdf_url: string | null;
    url: string;
};

export type ConteosEvaluacionesPrueba = {
    por_asignar: number;
    por_asignar_alerta: number;
    asignables: number;
    esperando_jefe: number;
    por_decidir: number;
    en_proceso: number;
    evaluadas: number;
    autorizadas: number;
    evaluadas_mes: number;
};
