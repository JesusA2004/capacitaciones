export type FormatoOficialItem = {
    id: number;
    slug: string;
    nombre: string;
    descripcion: string | null;
    tipo: string;
    tipo_etiqueta: string;
    aplica_a: 'colaborador' | 'candidato';
    empresa: string | null;
    file_type: string;
    lista: boolean;
    archivado: boolean;
    version_vigente: {
        id: number;
        numero: number;
        publicada_en: string | null;
        fidelidad: string;
        campos: number;
    } | null;
    borrador: number | null;
    veces_generado: number;
    ultimo_generado_at: string | null;
};
