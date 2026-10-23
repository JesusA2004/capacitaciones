/**
 * Módulo «Cartas compromiso» (GestionCartasCompromisoService).
 */

export type EstadoCartaCompromiso =
    'vigente' | 'cumplida' | 'vencida' | 'sustituida';

export type PestanaCartasCompromiso =
    | 'pendientes_firma'
    | 'vigentes'
    | 'por_vencer'
    | 'vencidas'
    | 'cumplidas'
    | 'firmadas'
    | 'todas'
    | 'sustituidas';

export type PersonaCartaCompromiso = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
};

export type CartaCompromisoFila = {
    id: number;
    folio: string | null;
    estado: EstadoCartaCompromiso;
    estado_etiqueta: string;
    por_vencer: boolean;
    fecha_limite: string;
    dias_restantes: number;
    generada_en: string;
    firmada: boolean;
    firmada_en: string | null;
    documentos: string[];
    colaborador: PersonaCartaCompromiso | null;
    /** PDF inline (rh.cartas-compromiso.pdf) para el visor centrado. */
    pdf_url: string;
};

export type EventoCartaCompromiso = {
    clave: 'generada' | 'firmada' | 'cumplida' | 'vencida' | 'sustituida';
    titulo: string;
    detalle: string;
    fecha: string;
};

export type CartaCompromisoDetalle = CartaCompromisoFila & {
    cumplida_en: string | null;
    vencida_en: string | null;
    dias_plazo: number;
    generada_por: string | null;
    firma_registrada_por: string | null;
    documentos_detalle: {
        document_type_id: number;
        nombre: string;
        entregado: boolean;
    }[];
    eventos: EventoCartaCompromiso[];
    archivo_firmado: {
        nombre: string;
        mime: string | null;
        url: string;
    } | null;
    puede: { descargar: boolean; subir_firmada: boolean };
};

export type IndicadoresCartasCompromiso = {
    vigentes: number;
    por_vencer: number;
    vencidas: number;
    cumplidas: number;
    firmadas: number;
    pendientes_firma: number;
};

export type ReglasCartaCompromiso = {
    max_documentos: number;
    dias_plazo: number;
    dias_por_vencer: number;
};

export type PreparacionCartaCompromiso = {
    colaborador: PersonaCartaCompromiso;
    pendientes: {
        document_type_id: number;
        nombre: string;
        descripcion: string | null;
        estado_etiqueta: string;
    }[];
    max_documentos: number;
    dias_plazo: number;
    fecha_limite: string;
    motivo_no_elegible: string | null;
    /** Se puede ver la carta (aunque aún no generar: más faltantes que el máximo). */
    puede_ver_carta: boolean;
    carta_abierta: {
        id: number;
        folio: string | null;
        estado_etiqueta: string;
        url: string;
        pdf_url: string;
    } | null;
};
