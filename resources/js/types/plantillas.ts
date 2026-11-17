export type DocumentoGeneradoItem = {
    id: number;
    plantilla: { id: number; nombre: string; tipo: string } | null;
    colaborador: { id: number; name: string; apellidos: string | null } | null;
    candidato: { id: number; nombre: string; apellidos: string | null } | null;
    generado_por: { id: number; name: string; apellidos: string | null } | null;
    generated_name: string;
    status: string;
    created_at: string;
};
