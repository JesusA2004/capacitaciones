export type OpcionIncapacidad = { value: string; label: string };

export type IncapacidadItem = {
    id: number;
    raiz_id: number;
    colaborador_id: number;
    colaborador: string;
    sucursal: string | null;
    puesto: string | null;
    tipo: string;
    tipo_etiqueta: string;
    subtipo_etiqueta: string | null;
    secuencia: string;
    secuencia_etiqueta: string;
    serie_folio_imss: string;
    fecha_inicio: string;
    dias: number;
    fecha_fin: string;
    en_curso: boolean;
    estatus: 'activa' | 'concluida' | 'cancelada';
    estatus_etiqueta: string;
    documentos_resumen: { etiqueta: string; cargado: boolean }[];
    motivo_cancelacion: string | null;
    puede_corregir: boolean;
};

export type ReglaDiasNomina = {
    clave: string;
    etiqueta: string;
    validada_por_rh: boolean;
    aviso: string | null;
};

export type ImpactoIncapacidad = {
    colaborador_id: number;
    nombre: string;
    sucursal: string | null;
    detalle: string;
    dias: number;
};

export type RectificacionNominaItem = {
    id: number;
    colaborador: string;
    folio: string | null;
    periodo: string;
    dias_antes: string | null;
    dias_despues: string;
    importe_despues: string;
    motivo: string;
};

export type DocumentoIncapacidad = {
    id: number;
    tipo: string;
    etiqueta: string;
    incapacidad_id: number;
    nombre_original: string;
    fecha_documento: string | null;
    cargado_por: string | null;
    cargado_en: string | null;
    vigente: boolean;
    motivo_reemplazo: string | null;
    puede_descargar: boolean;
};

export type EventoIncapacidad = {
    fecha: string;
    titulo: string;
    detalle: string | null;
    clase: 'evento' | 'incapacidad' | 'documento' | 'pendiente' | 'cancelada';
    pendiente: boolean;
};

export type CadenaIncapacidad = {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    subtipo_etiqueta: string | null;
    cuenta_siniestralidad: boolean | null;
    riesgo_folio: string | null;
    descripcion_evento: string | null;
    estatus: 'activa' | 'concluida' | 'cancelada';
    estatus_etiqueta: string;
    dias_totales: number;
    observaciones: string | null;
    incapacidades: IncapacidadItem[];
    documentos: DocumentoIncapacidad[];
    pendientes: string[];
    linea_de_tiempo: EventoIncapacidad[];
};

export type InicialAbierta = {
    id: number;
    tipo: string;
    subtipo_riesgo: string | null;
    etiqueta: string;
    ultimo_dia: string;
};
