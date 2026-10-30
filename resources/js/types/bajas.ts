/**
 * Módulo de Bajas y Causales (docs/BAJAS_Y_CAUSALES.md): catálogo de dos
 * niveles (forma de salida RV/NR/RE → causal), registro DIRECTO de la baja
 * (gerente o RH), bandeja «Bajas», expediente de la baja, recopilación de
 * documentos (Bajas y finiquitos) y tablero de rotación.
 */

export type FormaSalidaClave = 'RV' | 'NR' | 'RE' | 'OT';

/** Causal activa de una forma (CatalogoBajasService::causalArray). */
export type CausalBajaOpcion = {
    id: number;
    clave: string | null;
    nombre: string;
    ayuda: string | null;
    requiere_juridico: boolean;
    comentario_obligatorio: boolean;
    alerta: string | null;
    /** Foto oficial del expediente (URL protegida); null → iniciales. */
    foto_url?: string | null;
};

/** Forma de salida con sus causales (tarjeta grande de la solicitud). */
export type FormaSalidaOpcion = {
    value: FormaSalidaClave;
    etiqueta: string;
    resumen: string;
    descripcion: string;
    tipo_baja: string;
    token: string;
    causales: CausalBajaOpcion[];
};

/** Documento del catálogo (para armar los espacios en la solicitud). */
export type DocumentoCatalogoBaja = {
    id: number;
    nombre: string;
    forma: FormaSalidaClave | null;
    causal_id: number | null;
    obligatorio: boolean;
    sistema: string | null;
};

export type ArchivoBaja = {
    id: number;
    nombre: string;
    nombre_original: string;
    mime: string | null;
    tamano: number;
    subido_por: string | null;
    fecha: string | null;
    url: string;
    url_descarga: string;
};

/** Espacio de documento de la baja (BajaDocumentosService::espacios). */
export type EspacioDocumentoBaja = {
    id: number | null;
    clave: string | null;
    nombre: string;
    obligatorio: boolean;
    de_causal: boolean;
    cumplido: boolean;
    cumplido_por_sistema: string | null;
    archivos: ArchivoBaja[];
};

export type ColaboradorBaja = {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    fecha_ingreso?: string | null;
    /** Foto oficial del expediente (URL protegida); null → iniciales. */
    foto_url?: string | null;
};

export type LimitesBaja = {
    comentario_minimo: number;
    comentario_maximo: number;
    dias_futuro_maximo: number;
    dias_prescripcion: number;
    dias_habiles_tribunal: number;
    max_kb: number;
    mimes: string[];
};

/** Campos capturables de la baja (bloques 2 y 3 de la solicitud). */
export type CamposBaja = {
    forma_salida: FormaSalidaClave | null;
    causal_rotacion_id: number | null;
    fecha_baja: string;
    recontratable: string;
    comentarios: string;
    fecha_conocimiento_falta: string;
    entrega_aviso_rescision: string;
    fecha_entrega_aviso: string;
};

/** Fila de la bandeja (BajaConsultaService::fila). */
export type FilaBaja = {
    id: number;
    folio: string;
    colaborador: string;
    numero_empleado: string | null;
    sucursal: string | null;
    puesto: string | null;
    forma: FormaSalidaClave | null;
    forma_etiqueta: string | null;
    forma_token: string | null;
    causal: string | null;
    causal_migrada: boolean;
    estatus: string;
    estatus_etiqueta: string;
    fecha_baja: string | null;
    enviado_en: string | null;
    dias_en_espera: number | null;
    documentos_completos: boolean;
    documentos_faltantes: number;
    /** Obligatorios para cerrar (avance = total − faltantes). */
    documentos_total: number;
    requiere_juridico: boolean;
    /** Aviso «Integrar expediente probatorio» (no es una autorización). */
    integrar_expediente: boolean;
    /** en_tramite | faltan_documentos | lista_para_cerrar | cerrada | cancelada… */
    cubo: string;
    foto_url?: string | null;
    finiquito?: { id: number; estado: string; etiqueta: string } | null;
    origen: string;
};

/** Pestañas de Cierres laborales → Bajas (BajaConsultaService::pestanas). */
export type PestanasBajas = {
    bajas: boolean;
    finiquitos: boolean;
    rotacion: boolean;
    causales: boolean;
    conteo_bajas: number | null;
    conteo_finiquitos: number | null;
};

/** Panel «Generar documento de salida» (BajaDocumentoSalidaService::panel). */
export type PanelDocumentoSalida = {
    baja_id: number;
    folio: string;
    colaborador: string;
    forma: FormaSalidaClave | null;
    causal: string | null;
    fundamento_catalogo: string | null;
    documento: { clave: string; etiqueta: string } | null;
    abierta: boolean;
    faltantes: string[];
    generado: {
        id: number;
        version: number;
        fecha: string | null;
        firmado: boolean;
        desactualizado: boolean;
    } | null;
    firmados: ArchivoBaja[];
    evidencias: ArchivoBaja[];
    aviso: {
        fundamento: string;
        que_ocurrio: string;
        hechos_en: string;
        lugar: string;
        constataron: string;
        relato: string;
        testigos: { nombre: string; cargo: string }[];
        fecha_aviso: string;
        metodo_entrega: string;
        fecha_entrega: string;
    } | null;
    plazo: {
        dias: number;
        fecha_limite: string;
        mensaje: string;
        nota: string;
        validado: boolean;
        validado_por: string | null;
        validado_en: string | null;
    } | null;
    testigos_minimos: number;
    puede_validar_plazo: boolean;
};

/** Fila de la pestaña Finiquitos (FiniquitoConsultaService::fila). */
export type FilaFiniquitoBaja = {
    id: number;
    colaborador: string;
    foto_url: string | null;
    puesto: string | null;
    sucursal: string | null;
    forma: FormaSalidaClave | null;
    forma_etiqueta: string | null;
    forma_token: string | null;
    causal: string | null;
    tipo: 'finiquito' | 'liquidacion';
    tipo_etiqueta: string;
    monto: number;
    estado: string;
    estado_flujo: 'calculado' | 'revisado' | 'firmado' | 'pagado';
    subestado: string | null;
    fecha_pago: string | null;
    referencia_pago: string | null;
    baja_id: number | null;
};

/** Detalle editable de un finiquito (FiniquitoConsultaService::detalle). */
export type DetalleFiniquitoBaja = FilaFiniquitoBaja & {
    fecha_ingreso: string;
    fecha_baja: string;
    sueldo_diario: number;
    total_percepciones: number;
    total_deducciones: number;
    desglose: {
        clave: string;
        concepto_clave: string | null;
        concepto: string;
        tipo: string;
        importe: number;
        valor_calculado: number | null;
        ajustado: boolean;
    }[];
    historial_ajustes: {
        concepto: string;
        anterior: number;
        actualizado: number;
        motivo: string;
        usuario: string | null;
        fecha: string | null;
    }[];
    ultima_edicion: {
        accion: string;
        usuario: string | null;
        fecha: string | null;
    } | null;
    causal_implica_indemnizacion: boolean;
    advertencia_liquidacion: string | null;
    puede: {
        editar: boolean;
        recalcular: boolean;
        revisar: boolean;
        cambiar_tipo: boolean;
        recibo: boolean;
        subir_firmado: boolean;
        registrar_pago: boolean;
    };
};

/**
 * Números grandes de la bandeja (BajaConsultaService::resumen). Flujo sin
 * Jurídico (propuesta Etapa 1): las abiertas se reparten en En trámite,
 * Faltan documentos y Listas para cerrar.
 */
export type ResumenBajas = {
    en_tramite: number;
    faltan_documentos: number;
    lista_para_cerrar: number;
    cerradas: number;
    no_procede: number;
};

/** Estado de un documento en la recopilación. */
export type EstadoDocumentoRecopilacion = 'generado' | 'firmado' | 'pendiente';

/** Documento de una persona dada de baja (BajaRecopilacionService::tarjeta). */
export type DocumentoRecopilacion = {
    clave: string;
    nombre: string;
    origen: 'generado' | 'finiquito' | 'adjunto';
    estado: EstadoDocumentoRecopilacion;
    estado_etiqueta: string;
    obligatorio: boolean;
    /** Espacio de la baja al que se adjunta el que falta (o su firmado). */
    requerido_id: number | null;
    url: string | null;
    url_descarga: string | null;
    mime: string | null;
    fecha: string | null;
};

/** Tarjeta por persona de «Bajas y finiquitos» → Documentos. */
export type TarjetaRecopilacion = FilaBaja & {
    colaborador_id: number | null;
    cierre_id: number | null;
    foto_url: string | null;
    avance: { completos: number; total: number };
    documentos: DocumentoRecopilacion[];
    puede_adjuntar: boolean;
    puede_ver_baja: boolean;
};

/** Resumen de la recopilación (BajaRecopilacionService::resumen). */
export type ResumenRecopilacion = ResumenBajas & {
    documentos_pendientes: number;
};

export type BitacoraBaja = {
    id: number;
    accion: string;
    accion_etiqueta: string;
    campo: string | null;
    valor_anterior: string | null;
    valor_nuevo: string | null;
    motivo: string | null;
    usuario: string;
    fecha: string | null;
};

/** Expediente de la baja (BajaService::detalle). */
export type BajaDetalle = FilaBaja & {
    colaborador_id: number | null;
    fecha_ingreso: string | null;
    antiguedad_dias: number | null;
    causal_rotacion_id: number | null;
    causal_clave: string | null;
    causal_nombre: string | null;
    causal_ayuda: string | null;
    causal_migrada_de: string | null;
    comentarios: string | null;
    recontratable: string | null;
    recontratable_etiqueta: string | null;
    fecha_conocimiento_falta: string | null;
    entrega_aviso_rescision: string | null;
    entrega_aviso_rescision_etiqueta: string | null;
    fecha_entrega_aviso: string | null;
    es_rotacion: boolean;
    origen_etiqueta: string;
    motivo_original: string | null;
    motivo_rechazo: string | null;
    rechazado_area: string | null;
    motivo_cancelacion: string | null;
    validado_en: string | null;
    juridico_en: string | null;
    cerrado_en: string | null;
    acceso_suspendido_en: string | null;
    solicitado_por: string | null;
    cierre: {
        id: number;
        estado: string;
        estado_etiqueta: string;
        colaborador_id: number;
    } | null;
    errores: Record<string, string>;
    advertencias: { clave: string; mensaje: string }[];
    documentos: EspacioDocumentoBaja[];
    faltantes: string[];
    acciones: string[];
    bitacora: BitacoraBaja[];
};

/** Un mes del tablero (TableroRotacionService::mes). */
export type MesRotacion = {
    periodo: string;
    etiqueta: string;
    bajas: number | null;
    plantilla_promedio: number | null;
    indice: number | null;
    fuente: string;
    indice_sucursal?: number | null;
    bajas_sucursal?: number | null;
};

export type TableroRotacion = {
    filtros: {
        desde: string;
        hasta: string;
        sucursal_id: number | null;
        puesto_id: number | null;
        forma: FormaSalidaClave | null;
        causal_id: number | null;
    };
    mes_inicio_sistema: string;
    resumen: {
        mes: MesRotacion;
        acumulado: {
            anio: number;
            bajas: number;
            plantilla_promedio: number | null;
            indice: number | null;
            meses: number;
        };
        por_forma: {
            forma: FormaSalidaClave;
            etiqueta: string;
            token: string;
            bajas: number;
            porcentaje: number | null;
            indice: number | null;
        }[];
        rotacion_temprana: { bajas: number; porcentaje: number | null };
        antiguedad_promedio_dias: number | null;
        antiguedad_por_forma: {
            forma: FormaSalidaClave;
            etiqueta: string;
            dias: number | null;
        }[];
        calidad_expediente: {
            cerradas: number;
            completas: number;
            porcentaje: number | null;
        };
        bajas_sistema: number;
    };
    tendencia: MesRotacion[];
    causales: {
        clave: string | null;
        nombre: string;
        forma: FormaSalidaClave | null;
        token: string | null;
        bajas: number;
        porcentaje: number;
    }[];
    sucursales: {
        sucursal_id: number;
        sucursal: string;
        bajas: number;
        plantilla_promedio: number | null;
        indice: number | null;
        indice_mes: number | null;
        indice_mes_anterior: number | null;
        tendencia: 'sube' | 'baja' | 'igual' | null;
        por_forma: Record<FormaSalidaClave, number | null>;
        causal_principal: string | null;
        rotacion_temprana: number | null;
        antiguedad_promedio_dias: number | null;
        calidad_expediente: number | null;
    }[];
    puestos: {
        puesto: string;
        bajas: number;
        plantilla_promedio: number | null;
        indice: number | null;
        rotacion_temprana: number | null;
        antiguedad_promedio_dias: number | null;
    }[];
    opciones: {
        sucursales: { id: number; nombre: string }[];
        puestos: { id: number; nombre: string }[];
        formas: { value: FormaSalidaClave; label: string; token: string }[];
        causales: {
            id: number;
            clave: string | null;
            nombre: string;
            forma: FormaSalidaClave;
        }[];
    };
};

/** RN-09: baja previa no recontratable de un candidato. */
export type AntecedenteBaja = {
    folio: string;
    nombre: string;
    fecha_baja: string | null;
    recontratable: string;
    recontratable_etiqueta: string;
    causal: string | null;
    sucursal: string | null;
    coincidencia: string;
};
