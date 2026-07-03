export type ActividadItem = {
    id: number;
    leccion_id: number;
    titulo: string;
    instrucciones: string | null;
    tipo_entrega: string;
    calificacion_minima: number;
    fecha_limite: string | null;
};

export type EntregaActividadItem = {
    id: number;
    actividad_id: number;
    user_id: number;
    version: number;
    contenido_texto: string | null;
    url: string | null;
    recurso_multimedia_id: number | null;
    estado: string;
    calificacion: number | null;
    retroalimentacion: string | null;
    entregado_en: string;
    calificado_en: string | null;
};

export type EntregaCalificacionItem = {
    id: number;
    version: number;
    entregado_en: string;
    usuario: { id: number; name: string; apellidos: string | null };
    actividad: { id: number; titulo: string; leccion_id: number };
};

export type EntregaDetalleCalificacion = EntregaActividadItem & {
    usuario: { id: number; name: string; apellidos: string | null };
    actividad: ActividadItem;
    recursoMultimedia?: { id: number; nombre_original: string } | null;
};
