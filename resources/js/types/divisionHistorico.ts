/**
 * División del PDF histórico por checklist
 * (docs/DIVISION_EXPEDIENTE_HISTORICO.md) —
 * Rh\DivisionExpedienteHistoricoController::show.
 */
export type ConfianzaDivision = 'alta' | 'media' | 'baja' | 'manual';

export type PaginaDivision = {
    pagina: number;
    tipo: string | null;
    tipo_nombre: string | null;
    sugerido: string | null;
    confianza: ConfianzaDivision | null;
    puntaje: number;
    coincidencias: string[];
    metodo: 'texto' | 'ocr' | 'sin_texto';
    metodo_etiqueta: string | null;
    motivo: string | null;
    /** Corregida a mano por RH (el OCR ya no la cambia). */
    manual?: boolean;
};

export type ParteDivision = {
    id: number;
    orden: number;
    tipo: string | null;
    document_type_id: number | null;
    paginas: string;
    total_paginas: number;
    confianza: ConfianzaDivision | null;
    estado: 'asignada' | 'ya_cubierta' | 'sin_clasificar' | 'asignada_manual';
    estado_etiqueta: string;
    asignada: boolean;
    documento_estado: string | null;
    ver_url: string;
    /** Editor de hojas con las hojas de esta parte ya seleccionadas. */
    editor_url: string;
};

export type DatoDetectadoDivision = {
    campo: string;
    etiqueta: string;
    actual: string | null;
    valores: { valor: string; fuentes: string[] }[];
    estado:
        | 'faltante'
        | 'discrepancia'
        | 'conflicto_documentos'
        | 'coincide'
        | 'aplicado';
    estado_etiqueta: string | null;
    aplicado: { valor: string; por: number; en: string } | null;
};

export type DivisionHistorico = {
    id: number;
    estado: 'procesando' | 'completada' | 'fallida';
    estado_etiqueta: string;
    creada_en: string | null;
    ejecutado_por: string | null;
    total_paginas: number;
    paginas_clasificadas: number;
    paginas_sin_clasificar: number;
    paginas_sin_texto: number;
    ocr_disponible: boolean;
    ocr_motor: string | null;
    error: string | null;
    vigente: boolean;
    editor_url: string;
    paginas_manuales: number;
    revision: {
        version: number;
        por: string | null;
        en: string | null;
        cambios: number;
    } | null;
    advertencias: string[];
    paginas: PaginaDivision[];
    partes: ParteDivision[];
    datos: DatoDetectadoDivision[];
};

export type HistoricoDivision = {
    id: number;
    nombre: string;
    original: string;
    size: number | null;
    elegible: boolean;
    ver_url: string;
    division: DivisionHistorico | null;
};

export type ChecklistDivision = {
    requeridos: number;
    entregados: number;
    aprobados: number;
    en_revision: number;
    porcentaje: number;
    completo: boolean;
    cubierto: boolean;
    faltantes: string[];
    documentos: {
        document_type_id: number;
        nombre: string;
        estado: string;
        estado_etiqueta: string;
        documento_id: number | null;
        /** Páginas del PDF histórico de donde salió, si vino de la división. */
        desde_historico: string | null;
    }[];
};

export type DetalleDivisionHistorico = {
    historicos: HistoricoDivision[];
    checklist: ChecklistDivision;
    ocr: { disponible: boolean; motor: string; motivo: string | null };
    /** Tipos del checklist que ya tienen un documento vigente que cubre. */
    tipos_cubiertos?: number[];
};

/**
 * Editor de hojas del PDF histórico —
 * Rh\DivisionExpedienteHistoricoController::editor.
 */
export type DocumentoPlanEditor = {
    document_type_id: number | null;
    paginas: number[];
};

export type HojaEditorDivision = {
    pagina: number;
    /** Lo que dijo el OCR (nombre del tipo), aunque RH la haya corregido. */
    tipo_ocr: string | null;
    confianza: ConfianzaDivision | null;
    metodo: 'texto' | 'ocr' | 'sin_texto' | null;
    motivo: string | null;
    manual: boolean;
};

export type TipoEditor = {
    id: number;
    nombre: string;
    clave: string;
    /** false = opcional del checklist (pagaré, antecedentes, licencia). */
    requerido?: boolean;
};

export type RevisionEditor = {
    version: number;
    por: string | null;
    en: string | null;
    cambios: number;
    detalle: CambioHoja[];
};

export type CambioHoja = { pagina: number; de: string; a: string };

export type AccionDocumentoReorganizado =
    | 'sin_cambios'
    | 'nueva_version'
    | 'carga_nueva'
    | 'ya_cubierta'
    | 'duplicado'
    | 'sin_clasificar';

export type DocumentoResultanteHojas = {
    orden: number;
    document_type_id: number | null;
    tipo: string | null;
    paginas: number[];
    rango: string;
    total_paginas: number;
    accion: AccionDocumentoReorganizado;
    accion_etiqueta: string;
    parte_id: number | null;
    pdf_url: string;
};

export type VistaPreviaEditor = {
    hash_plan: string;
    documentos: DocumentoResultanteHojas[];
    cambios: CambioHoja[];
    advertencias: { nivel: 'aviso' | 'info'; mensaje: string }[];
    faltantes: string[];
    sin_cambios: boolean;
    revision_actual: number;
};
