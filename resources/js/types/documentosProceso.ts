/**
 * "Documentos del proceso" (motor documental). El backend
 * (DocumentoProcesoService) decide qué documento toca, su estado y las
 * acciones permitidas; la web y la app solo renderizan este payload.
 */
export type TipoRegistroDocumental =
    | 'contrato'
    | 'cierre'
    | 'solicitud'
    | 'prestamo'
    | 'evaluacion'
    | 'entrega_activo';

export type AccionDocumento = {
    clave:
        | 'generar'
        | 'regenerar'
        | 'descargar'
        | 'marcar_impreso'
        | 'registrar_firma'
        | 'registrar_envio'
        | 'registrar_recepcion'
        | 'subir_escaneo'
        | 'archivar'
        | string;
    etiqueta: string;
    tipo: 'primaria' | 'secundaria' | 'peligro' | string;
};

export type DocumentoGeneradoResumen = {
    id: number;
    titulo?: string | null;
    archivo?: string | null;
    estado: string | null;
    estado_etiqueta: string | null;
    version_plantilla?: number | null;
    master_familia?: string | null;
    generado_en?: string | null;
    generado_por?: string | null;
    fidelidad?: string | null;
    descargas?: number;
    impreso?: boolean;
    firmado?: boolean;
    escaneado?: boolean;
    archivado?: boolean;
};

export type ItemDocumentoProceso = {
    clave: string;
    nombre: string;
    motivo: string;
    estado: string;
    estado_etiqueta: string;
    bloqueo: string | null;
    formato_faltante: {
        code: string;
        mensaje: string;
        detalle: Record<string, string | null>;
    } | null;
    master: {
        id: number;
        familia: string | null;
        version: number;
        nombre: string;
        motor: string;
        heredada?: boolean;
    } | null;
    modulo?: string;
    neto?: string | null;
    requiere: {
        impresion: boolean;
        firma_fisica: boolean;
        huella: boolean;
        testigos: boolean;
        cantidad_testigos: number;
        envio_corporativo: boolean;
    };
    documento: DocumentoGeneradoResumen | null;
    historial: {
        id: number;
        estado: string | null;
        estado_etiqueta: string | null;
        version_plantilla: number | null;
        generado_en: string | null;
        motivo_cancelacion: string | null;
    }[];
    acciones: AccionDocumento[];
};

export type PuntoChecklistBaja = {
    clave: string;
    etiqueta: string;
    cumplido: boolean;
    aplica: boolean;
};

export type SeccionDocumentosProceso = {
    proceso: string;
    titulo: string;
    descripcion: string;
    registro: { tipo: TipoRegistroDocumental; id: number };
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        puesto: string | null;
        grupo_documental: string | null;
        sucursal: string | null;
        dado_de_baja: boolean;
    };
    bloqueo: string | null;
    expediente_completo?: boolean;
    acciones: AccionDocumento[];
    documentos: ItemDocumentoProceso[];
    negativa?: {
        registrada_en: string;
        documentos: string[];
        testigos: { nombre: string; cargo: string }[];
        participantes: Record<string, string>;
        finiquito_a_disposicion: boolean;
        observaciones: string | null;
    } | null;
    checklist?: PuntoChecklistBaja[] | null;
};

export type DatoFaltante = {
    campo: string;
    base: string;
    fuente: 'colaborador' | 'sucursal' | 'manual' | 'proceso' | string;
    columna: string;
    etiqueta: string;
    tipo: string;
    editable: boolean;
    documentos?: string[];
};

export type MasterAdminFila = {
    familia: string;
    clave: string | null;
    nombre: string;
    proceso: string | null;
    proceso_etiqueta: string | null;
    aplica_a: string;
    empresa: string;
    motor: string;
    operativo: boolean;
    version_activa: number | null;
    estado: string;
    activo: boolean;
    master_id: number | null;
    detectados: number;
    mapeados: number;
    pendientes: number;
    ultima_prueba_en: string | null;
    versiones: {
        id: number;
        version: number;
        activo: boolean;
        estado: string | null;
        original: string | null;
        cargado_en: string | null;
        cargado_por?: string | null;
    }[];
};

export type MasterDetalle = {
    id: number;
    familia: string | null;
    clave: string | null;
    nombre: string;
    version: number;
    activo: boolean;
    estado: string | null;
    motor: string;
    proceso: string | null;
    grupos: string[];
    original: { nombre: string | null; sha256: string | null };
    master_hash: string | null;
    fuentes: { nombre: string; sha256: string }[];
    banderas: Record<string, boolean | number>;
    representante: string | null;
    reporte: {
        detectados: number;
        mapeados: number;
        firmas: number;
        pendientes: { referencia: string; tipo: string; contexto: string }[];
        reglas_pendientes: { regla: number; campo: string; contexto: string }[];
        campos: string[];
        paginas: { numero: number; ancho: number; alto: number }[];
        imagenes_conservadas: number;
    };
    observaciones: string[];
    ultima_prueba: { en: string; resultado: Record<string, unknown> | null } | null;
    cargada_por: string | null;
    historial: {
        accion: string;
        por: string | null;
        en: string | null;
        propiedades: Record<string, unknown>;
    }[];
};
