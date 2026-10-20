/**
 * Módulo de Bajas y Causales (docs/BAJAS_Y_CAUSALES.md): catálogo de dos
 * niveles (forma de salida RV/NR/RE → causal), solicitud del gerente,
 * bandeja de RH, expediente de la baja y tablero de rotación.
 */

export type FormaSalidaClave = 'RV' | 'NR' | 'RE';

/** Causal activa de una forma (CatalogoBajasService::causalArray). */
export type CausalBajaOpcion = {
    id: number;
    clave: string | null;
    nombre: string;
    ayuda: string | null;
    requiere_juridico: boolean;
    comentario_obligatorio: boolean;
    alerta: string | null;
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

/** Baja en el formulario del gerente (BajaService::formulario). */
export type BajaFormulario = {
    id: number;
    folio: string;
    estatus: string;
    estatus_etiqueta: string;
    motivo_rechazo: string | null;
    colaborador: ColaboradorBaja;
    forma_salida: FormaSalidaClave | null;
    causal_rotacion_id: number | null;
    fecha_baja: string | null;
    recontratable: string | null;
    comentarios: string | null;
    fecha_conocimiento_falta: string | null;
    entrega_aviso_rescision: string | null;
    fecha_entrega_aviso: string | null;
    documentos: EspacioDocumentoBaja[];
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
    requiere_juridico: boolean;
    origen: string;
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
