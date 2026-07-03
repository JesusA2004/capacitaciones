export type AsignableCurso = {
    id: number;
    titulo: string;
};

export type AsignacionItem = {
    id: number;
    nombre: string;
    asignable_type: string;
    asignable: AsignableCurso | null;
    responsable: { id: number; name: string; apellidos: string | null } | null;
    fecha_inicio: string | null;
    fecha_limite: string | null;
    obligatoria: boolean;
    activa: boolean;
    cancelada_en: string | null;
    asignaciones_usuario_count: number;
};

export type DestinoAsignacionForm = {
    tipo: string;
    id: number | null;
};

export type OpcionRol = {
    id: number;
    name: string;
};

export type PrevisualizacionAsignacion = {
    total: number;
    posibles_duplicados: number;
    muestra: { id: number; nombre: string; email: string }[];
};
