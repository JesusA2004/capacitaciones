export type AvisoItem = {
    id: number;
    titulo: string;
    mensaje: string;
    alcance: 'todos' | 'colaborador';
    colaborador_objetivo_id: number | null;
    colaborador_objetivo?: { id: number; name: string; apellidos: string | null } | null;
    imagen_path: string | null;
    creado_por?: { id: number; name: string; apellidos: string | null } | null;
    enviado_en: string | null;
    created_at: string;
    lecturas_count?: number;
    leido?: boolean;
};
