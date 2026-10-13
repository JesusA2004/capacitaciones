/** Adjunto opcional de un aviso: se abre por /{id}/archivo (endpoint autorizado). */
export type AdjuntoAviso = {
    tipo: 'imagen' | 'pdf';
    nombre: string | null;
    tamano: number | null;
    mime: string | null;
};

export type AvisoItem = {
    id: number;
    titulo: string;
    mensaje: string;
    alcance: 'todos' | 'colaborador';
    colaborador_objetivo_id: number | null;
    colaborador_objetivo?: { id: number; name: string; apellidos: string | null } | null;
    archivo: AdjuntoAviso | null;
    creado_por?: { id: number; name: string; apellidos: string | null } | null;
    enviado_en: string | null;
    created_at: string;
    lecturas_count?: number;
    leido?: boolean;
};
