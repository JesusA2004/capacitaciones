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
        url: string;
        nombre: string;
        mime: string | null;
    } | null;
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
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    jefe: string | null;
    fecha_ingreso: string | null;
    meses_periodo_prueba: number | null;
    ciclo_url: string;
};

export type PersonaPeriodoPrueba = PersonaPeriodoBase & {
    fin_periodo: string;
    fuente: 'contrato' | 'fecha_ingreso';
    fuente_etiqueta: string;
    dias_restantes: number;
    dias_transcurridos: number;
    alerta: boolean;
    tiene_contrato: boolean;
    contrato_generado: boolean;
    evaluacion: {
        id: number;
        estado: string;
        estado_etiqueta: string;
    } | null;
};
