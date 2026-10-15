/**
 * División del PDF histórico por checklist
 * (docs/DIVISION_EXPEDIENTE_HISTORICO.md) —
 * Rh\DivisionExpedienteHistoricoController::show.
 */
export type ConfianzaDivision = 'alta' | 'media' | 'baja';

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
};
