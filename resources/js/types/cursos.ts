export type ResponsableOpcion = {
    id: number;
    name: string;
    apellidos: string | null;
};

export type EstadoCursoOpcion = {
    value: string;
    etiqueta: string;
};

export type LeccionRequisitoItem = {
    id: number;
    titulo: string;
};

export type RecursoMultimediaResumen = {
    id: number;
    duracion_segundos: number | null;
};

export type LeccionItem = {
    id: number;
    curso_modulo_id: number;
    titulo: string;
    tipo: string;
    contenido: string | null;
    url: string | null;
    recurso_multimedia_id: number | null;
    recursoMultimedia?: RecursoMultimediaResumen | null;
    obligatoria: boolean;
    orden: number;
    duracion_estimada_minutos: number | null;
    requisitos?: LeccionRequisitoItem[];
};

export type CursoModuloItem = {
    id: number;
    curso_id: number;
    titulo: string;
    descripcion: string | null;
    orden: number;
    lecciones: LeccionItem[];
};

export type CursoRequisitoItem = {
    id: number;
    titulo: string;
};

export type CursoItem = {
    id: number;
    titulo: string;
    descripcion: string | null;
    objetivo: string | null;
    duracion_estimada_minutos: number | null;
    estado: string;
    disponible_desde: string | null;
    disponible_hasta: string | null;
    calificacion_minima: number | null;
    intentos_maximos: number | null;
    requiere_orden: boolean;
    genera_constancia: boolean;
    alcance_global: boolean;
    etiquetas: string[] | null;
    responsable_id: number | null;
    responsable?: ResponsableOpcion | null;
    publicado_en: string | null;
    modulos?: CursoModuloItem[];
    modulos_count?: number;
    requisitosPrevios?: CursoRequisitoItem[];
};
