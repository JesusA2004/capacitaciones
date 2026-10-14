export type SaldoVacaciones = {
    antiguedad_anios: number;
    vigencia_inicio: string | null;
    vigencia_fin: string | null;
    dias_generados: number;
    /** Solo lo usado dentro de PEOPLE (solicitudes aprobadas) — para el total visible usa `dias_usados_total`. */
    dias_usados: number;
    dias_usados_people: number;
    dias_usados_historicos: number;
    dias_usados_total: number;
    dias_en_solicitud: number;
    dias_ajustes_historicos: number;
    ajustes_a_favor: number;
    dias_disponibles: number;
};

export type SolicitudVacacionesItem = {
    id: number;
    user_id: number;
    usuario?: { id: number; name: string; apellidos: string | null } | null;
    fecha_inicio: string;
    fecha_fin: string;
    dias_solicitados: number;
    comentario: string | null;
    estado: string;
    motivo_rechazo: string | null;
    created_at: string;
};
