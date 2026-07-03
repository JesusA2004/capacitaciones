export type RecursoMultimediaOpcion = {
    id: number;
    tipo: string;
    nombre_original: string;
};

export type TipoRecursoOpcion = {
    value: string;
    etiqueta: string;
};

export type RecursoMultimediaItem = {
    id: number;
    tipo: string;
    nombre_original: string;
    estado: string;
    tamano_bytes: number | null;
    duracion_segundos: number | null;
    error_procesamiento: string | null;
    subidoPor: { id: number; name: string; apellidos: string | null } | null;
    created_at: string;
};
