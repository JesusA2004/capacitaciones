export type SaldoVacaciones = {
    antiguedad_anios: number;
    vigencia_inicio: string | null;
    vigencia_fin: string | null;
    dias_generados: number;
    dias_usados: number;
    dias_en_solicitud: number;
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
