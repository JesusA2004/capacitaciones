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
};

export type AsistenciaItem = {
    id: number;
    sesion_en_vivo_id: number;
    user_id: number;
    estado: string;
    motivo_correccion: string | null;
    usuario: { id: number; name: string; apellidos: string | null };
};

export type SesionConAsistencias = SesionEnVivoItem & {
    asistencias: AsistenciaItem[];
};
