export type FormatoOficialItem = {
    id: number;
    slug: string;
    nombre: string;
    descripcion: string | null;
    tipo: string;
    tipo_etiqueta: string;
    file_type: string;
    lista: boolean;
    veces_generado: number;
    ultimo_generado_at: string | null;
};

export type CampoOverlay = {
    pagina: number;
    x: number;
    y: number;
    font_size: number;
    align: 'left' | 'center' | 'right';
    max_width: number;
    color: string;
    enabled: boolean;
};

export type OverlayConfig = Record<string, CampoOverlay>;

export type CampoDisponible = {
    clave: string;
    etiqueta: string;
};

export type PersonaDisponible = {
    id: number;
    name?: string;
    nombre?: string;
    apellidos: string | null;
};
