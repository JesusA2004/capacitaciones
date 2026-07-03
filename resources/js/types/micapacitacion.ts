export type InscripcionResumen = {
    id: number;
    estado: string;
    iniciado_en: string | null;
    completado_en: string | null;
    curso: {
        id: number;
        titulo: string;
        duracion_estimada_minutos: number | null;
        genera_constancia: boolean;
    };
};

export type EstadoLeccion = {
    completada: boolean;
    bloqueada: boolean;
    motivo_bloqueo: string | null;
};

export type CertificadoItem = {
    id: number;
    folio: string;
    emitido_en: string;
};
