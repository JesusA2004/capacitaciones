export type SesionEnVivoItem = {
    id: number;
    leccion_id: number;
    titulo: string;
    descripcion: string | null;
    proveedor: string;
    fecha_inicio: string;
    duracion_minutos: number;
    enlace_reunion: string | null;
    estado: string;
    porcentaje_minimo_asistencia: number;
    minutos_minimos_asistencia: number | null;
    tolerancia_minutos: number;
    criterio_cumplimiento: string;
    considerar_tiempo_previo: boolean;
    considerar_tiempo_posterior: boolean;
};

export type EntradaSalidaItem = {
    id: number;
    inicio: string;
    fin: string | null;
    duracion_segundos: number | null;
    origen: string;
};

export type SesionParticipanteItem = {
    id: number;
    correo_detectado: string | null;
    nombre_mostrado: string | null;
    tipo_participante: string;
    estado_identificacion: string;
    minutos_acumulados: number;
    porcentaje_sesion: number;
    numero_reconexiones: number;
    resultado_calculado: string | null;
    entradas_salidas?: EntradaSalidaItem[];
};

export type AsistenciaItem = {
    id: number;
    sesion_en_vivo_id: number;
    user_id: number;
    estado: string;
    minutos_totales: number | null;
    porcentaje_sesion: number | null;
    numero_reconexiones: number;
    motivo_estado: string | null;
    sincronizado_en: string | null;
    motivo_correccion: string | null;
    estado_anterior: string | null;
    minutos_anteriores: number | null;
    corregido_por: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    usuario: { id: number; name: string; apellidos: string | null };
    sesion_participante?: SesionParticipanteItem | null;
};

export type SesionConAsistencias = SesionEnVivoItem & {
    asistencias: AsistenciaItem[];
};
