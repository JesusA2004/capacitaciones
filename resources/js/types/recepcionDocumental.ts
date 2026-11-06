/**
 * Recepción documental RH (docs/RECEPCION_DOCUMENTAL_RH.md): tipos de las
 * pantallas Rh/RecepcionDocumental/*. Todo lo calcula el backend
 * (RecepcionDocumentalService); la pantalla solo muestra y palomea.
 */

export type EstadoCorteRecepcion =
    'en_preparacion' | 'abierto' | 'con_pendientes' | 'completo' | 'cerrado';

export type EstadoRecepcionDocumento =
    | 'pendiente'
    | 'recibido'
    | 'recibido_con_observacion'
    | 'no_recibido'
    | 'no_aplica';

export type MetricasRecepcion = {
    esperados: number;
    recibidos: number;
    pendientes: number;
    no_recibidos: number;
    incidencias: number;
    no_aplica: number;
    renglones: number;
    cumplimiento: number;
};

export type CorteRecepcion = {
    id: number;
    periodo: string;
    etiqueta: string;
    fecha_inicio: string;
    fecha_fin: string;
    dias: number;
    estado: EstadoCorteRecepcion;
    estado_etiqueta: string;
    materializado_en: string | null;
    cerrado_en: string | null;
    cerrado_por: string | null;
    observaciones_cierre: string | null;
    reabierto_conteo: number;
    reabierto_en: string | null;
    reabierto_por: string | null;
    motivo_reapertura: string | null;
    metricas: MetricasRecepcion;
};

export type RenglonRecepcion = {
    id: number;
    corte_id: number;
    regla: string;
    regla_etiqueta: string;
    regla_orden: number;
    tipo_documento: string;
    tipo_etiqueta: string;
    origen: { tipo: string; id: number };
    colaborador: {
        id: number | null;
        nombre: string | null;
        numero_empleado: string | null;
        puesto: string | null;
    };
    sucursal: { id: number | null; nombre: string };
    fecha_evento: string | null;
    evento_descripcion: string | null;
    cantidad_esperada: number;
    cantidad_recibida: number;
    /** Lote de listas de asistencia: activos de la sucursal en esa semana. */
    integrantes?:
        | {
              id: number;
              nombre: string;
              numero_empleado: string | null;
          }[]
        | null;
    estado: EstadoRecepcionDocumento;
    estado_etiqueta: string;
    justificacion: string | null;
    no_aplica_automatico: boolean;
    recibido_en: string | null;
    recibido_por: string | null;
    observaciones: string | null;
    incidencia: boolean;
    tiene_evidencia: boolean;
    evidencia_nombre: string | null;
    documento_digital: {
        id: number;
        titulo: string | null;
        estado: string | null;
    } | null;
    actualizado_en: string | null;
};

export type GrupoSucursalRecepcion = {
    sucursal_id: number | null;
    nombre: string;
    metricas: MetricasRecepcion;
};

export type GrupoTipoRecepcion = {
    tipo_documento: string;
    etiqueta: string;
    metricas: MetricasRecepcion;
};

export type GrupoReglaRecepcion = {
    regla: string;
    etiqueta: string;
    orden: number;
    activa: boolean;
    sin_eventos: string;
    metricas: MetricasRecepcion;
};

export type AgrupacionesRecepcion = {
    sucursales: GrupoSucursalRecepcion[];
    tipos: GrupoTipoRecepcion[];
    reglas: GrupoReglaRecepcion[];
};

export type OpcionRecepcion = { value: string | number; label: string };

export type OpcionesFiltrosRecepcion = {
    sucursales: OpcionRecepcion[];
    tipos: OpcionRecepcion[];
    reglas: OpcionRecepcion[];
    estados: OpcionRecepcion[];
};

export type FiltrosRecepcion = {
    sucursal_id: number | null;
    sin_sucursal: boolean;
    regla: string | null;
    tipo_documento: string | null;
    colaborador: string | null;
    estado: string | null;
    recibido: 'si' | 'no' | null;
};

export type PermisosRecepcion = {
    generar: boolean;
    recibir: boolean;
    observar: boolean;
    cerrar: boolean;
    reabrir: boolean;
    descargar_pdf: boolean;
};

export type VersionPdfRecepcion = {
    id: number;
    variante: 'control' | 'estado';
    version: number;
    nombre: string;
    generado_en: string | null;
    generado_por: string | null;
};

export type HistorialRecepcion = {
    accion: string;
    por: string | null;
    en: string | null;
    propiedades: Record<string, unknown>;
};

export type ReglaResumenRecepcion = {
    clave: string;
    etiqueta: string;
    activa: boolean;
    pendiente: string | null;
};
