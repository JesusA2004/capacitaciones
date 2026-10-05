/**
 * "Documentos del proceso" (motor documental). El backend
 * (DocumentoProcesoService) decide qué documento toca, su estado, la
 * siguiente acción y las acciones permitidas; la web y la app solo
 * renderizan este payload.
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
        | 'nueva_revision'
        | 'descargar'
        | 'descargar_word'
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

export type PasoLineaTiempo = {
    clave: string;
    etiqueta: string;
    estado: 'hecho' | 'actual' | 'pendiente';
};

export type DocumentoGeneradoResumen = {
    id: number;
    titulo?: string | null;
    archivo?: string | null;
    estado: string | null;
    estado_etiqueta: string | null;
    version_plantilla?: number | null;
    master_familia?: string | null;
    master_version?: number | null;
    generado_en?: string | null;
    generado_por?: string | null;
    fidelidad?: string | null;
    conversion_engine?: string | null;
    paginas?: number | null;
    tiene_word?: boolean;
    archivo_disponible?: boolean;
    revision_de_id?: number | null;
    motivo_revision?: string | null;
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
        cobertura_url?: string | null;
    } | null;
    master: {
        id: number;
        familia: string | null;
        version: number;
        nombre: string;
        motor: string;
        heredada?: boolean;
        etiqueta?: string;
        diseno_validado?: boolean;
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
    linea_tiempo?: PasoLineaTiempo[];
    siguiente_accion?: AccionDocumento | null;
    historial: {
        id: number;
        estado: string | null;
        estado_etiqueta: string | null;
        version_plantilla: number | null;
        generado_en: string | null;
        motivo_cancelacion: string | null;
        puede_descargar?: boolean;
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
    cobertura_url?: string | null;
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
    persistencia?: 'colaborador' | 'sucursal' | 'documento' | string;
    control?:
        'select' | 'fecha' | 'hora' | 'correo' | 'moneda' | 'texto' | string;
    opciones?: { value: string; label: string }[];
    sugerencias?: string[];
    documentos?: string[];
};

// ───────── Administración → Documentos maestros ─────────

export type EstadoEjecutivoMaster =
    'listo' | 'requiere_revision' | 'bloqueado' | 'sin_formato' | 'referencia';

export type EstadoDisenoMaster =
    'validado' | 'sin_validar' | 'fallido' | 'excepcion' | 'no_aplica';

export type VersionMaster = {
    id: number;
    version: number;
    activo: boolean;
    estado: string | null;
    diseno: EstadoDisenoMaster;
    original: string | null;
    cargado_en: string | null;
    cargado_por?: string | null;
    activado_en?: string | null;
    activado_por?: string | null;
    ultima_prueba_en?: string | null;
    fidelidad?: string | null;
    similitud?: number | null;
    excepcional?: boolean;
};

export type MasterAdminFila = {
    familia: string;
    clave: string | null;
    nombre: string;
    proceso: string | null;
    proceso_etiqueta: string | null;
    grupos: string[];
    aplica_a: string;
    empresa: string;
    motor: string;
    operativo: boolean;
    version_activa: number | null;
    estado: string;
    estado_ejecutivo: EstadoEjecutivoMaster;
    diseno: EstadoDisenoMaster;
    activo: boolean;
    master_id: number | null;
    detectados: number;
    mapeados: number;
    pendientes: number;
    ultima_prueba_en: string | null;
    versiones_sin_validar: number;
    versiones: VersionMaster[];
};

export type KpisMaestros = {
    formatos_activos: number;
    requieren_revision: number;
    puestos_sin_cobertura: number;
    versiones_sin_validar: number;
};

export type BloqueoActivacion = {
    clave: string;
    mensaje: string;
    excepcionable: boolean;
};

export type FuenteDocumento = {
    fuente: string;
    disponible: boolean;
    sustitucion: string | null;
};

export type MasterDetalle = {
    id: number;
    familia: string | null;
    clave: string | null;
    nombre: string;
    version: number;
    activo: boolean;
    estado: string | null;
    estado_ejecutivo: EstadoEjecutivoMaster;
    motor: string;
    proceso: string | null;
    grupos: string[];
    diseno: EstadoDisenoMaster;
    resumen: {
        documento: string;
        version: number;
        proceso: string | null;
        aplica_a: string;
        cargada_por: string | null;
        cargada_en: string | null;
        activada_por: string | null;
        activada_en: string | null;
        ultima_prueba_en: string | null;
        paginas: number | null;
    };
    calidad: {
        estado: 'pending' | 'passed' | 'failed';
        etiqueta: string;
        similitud: number | null;
        paginas_original: number | null;
        paginas_prueba: number | null;
        motor: string | null;
        fidelidad: string | null;
        problemas: string[];
        advertencias: string[];
        desbordes: { campo: string; valor: string; razon: string }[];
        por_pagina: { pagina: number; similitud: number }[];
        estructura: Record<string, unknown> | null;
        revisado_en: string | null;
        revisado_por: string | null;
        excepcion: string | null;
        impedimento: string | null;
    };
    fuentes_documento: FuenteDocumento[];
    activacion: {
        puede: boolean;
        bloqueos: BloqueoActivacion[];
        excepcion_permitida: boolean;
    };
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
        reglas: number;
        campos: string[];
        paginas: { numero: number; ancho: number; alto: number }[];
        imagenes_conservadas: number;
    };
    observaciones: string[];
    versiones: VersionMaster[];
    ultima_prueba: {
        en: string;
        resultado: Record<string, unknown> | null;
    } | null;
    cargada_por: string | null;
    historial: {
        accion: string;
        por: string | null;
        en: string | null;
        propiedades: Record<string, unknown>;
    }[];
};

export type ResultadoPruebaMaster = {
    colaborador: { id: number; nombre: string; numero_empleado: string | null };
    paginas: { original: number | null; generado: number };
    fidelidad: string;
    conversor: string;
    diseno: EstadoDisenoMaster;
    campos: { llenos: number; total: number };
    faltantes: string[];
    desbordes: {
        campo: string;
        etiqueta?: string;
        valor: string;
        razon: string;
    }[];
    fuentes: { ok: boolean; detalle: FuenteDocumento[] };
    patron: {
        domicilio: {
            valor: string;
            fuente: 'fiscal' | 'sucursal' | 'predeterminado' | string;
            configurado: string;
        };
        representante: {
            valor: string;
            fuente: 'empresa' | 'predeterminado' | string;
            modo?: 'empresa' | 'fijo_juridico' | 'no_aplica' | string;
        };
    };
    url_resultado: string;
    url_original: string;
};

export type ColaboradorBusqueda = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
};

// ───────── Cobertura documental por puesto ─────────

export type CeldaCobertura = {
    estado: 'ok' | 'general' | 'sin_validar' | 'falta' | 'no_aplica';
    nivel: 'puesto' | 'grupo' | 'general' | null;
    master: {
        id: number;
        familia: string | null;
        nombre: string;
        version: number;
    } | null;
};

export type PuestoCobertura = {
    id: number;
    nombre: string;
    grupo: string | null;
    grupo_etiqueta: string | null;
    no_requiere: boolean;
    motivo_sin_documentos: string | null;
    estado: 'completo' | 'incompleto' | 'sin_decision' | 'excluido';
    estado_etiqueta: string;
    faltantes: string[];
    celdas: Record<string, CeldaCobertura>;
};

export type ReporteCobertura = {
    columnas: { clave: string; etiqueta: string; requerida: boolean }[];
    puestos: PuestoCobertura[];
    resumen: {
        total: number;
        completos: number;
        incompletos: number;
        sin_decision: number;
        excluidos: number;
    };
};
