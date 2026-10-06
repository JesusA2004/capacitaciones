/**
 * Diseño de página de documentos maestros (presets de layout + biblioteca
 * de fondos). Espejo de App\Services\DocumentosMaestros\LayoutDocumentoService.
 */
export type AjusteFondo = 'stretch' | 'contain' | 'cover';

export type LayoutDocumento = {
    page?: {
        margins_cm?: {
            top?: number;
            right?: number;
            bottom?: number;
            left?: number;
        } | null;
    };
    paragraph?: {
        left_indent_cm?: number | null;
        right_indent_cm?: number | null;
        space_before_pt?: number | null;
        space_after_pt?: number | null;
        justify?: boolean;
        keep_lines?: boolean;
    };
    background?: {
        asset_id: number | null;
        apply_to: 'all_pages' | 'first_page';
        fit: AjusteFondo;
        opacity: number;
    } | null;
    header_logo_enabled?: boolean;
};

export type AreaSegura = {
    top: number | null;
    right: number | null;
    bottom: number | null;
    left: number | null;
};

export type FondoDocumento = {
    id: number;
    nombre: string;
    slug: string;
    version: number;
    tipo: string;
    url_imagen: string;
    mime_type: string;
    width: number;
    height: number;
    sha256: string;
    fit_mode: AjusteFondo;
    default_opacity: number;
    safe_area: AreaSegura | null;
    activo: boolean;
    reemplaza_a_id: number | null;
    en_uso: { presets: number; familias: number; documentos: number };
    puede_eliminar: boolean;
    creado_por: string | null;
    created_at: string | null;
};

export type PresetLayout = {
    id: number;
    slug: string;
    nombre: string;
    config: LayoutDocumento;
    familias: string[];
};

export type DisenoFamilia = {
    familia: string;
    preset: { id: number; slug: string; nombre: string } | null;
    presets: { id: number; slug: string; nombre: string }[];
    fondos: FondoDocumento[];
    overrides: LayoutDocumento | null;
    tiene_personalizacion: boolean;
    efectivo: LayoutDocumento | null;
    pagina: {
        page_w_mm: number;
        page_h_mm: number;
        top_mm: number;
        right_mm: number;
        bottom_mm: number;
        left_mm: number;
    } | null;
    advertencias: string[];
};

/** Documentos administrativos HTML → PDF (App\Enums\FamiliaAdministrativa). */
export type FamiliaAdministrativaResumen = {
    familia: string;
    nombre: string;
    descripcion: string;
    estado: 'por_defecto' | 'borrador' | 'activa' | 'activa_con_borrador';
    version_activa: number | null;
    borrador_version: number | null;
    motor: 'browsershot' | 'dompdf';
    motor_etiqueta: string;
    activada_en: string | null;
    documentos_generados: number;
};

/** Diseño de un documento administrativo (DisenoAdministrativoService). */
export type DisenoAdministrativo = {
    page: {
        size: 'letter' | 'a4';
        orientation: 'portrait' | 'landscape';
        margins_mm: {
            top: number;
            right: number;
            bottom: number;
            left: number;
        };
    };
    typography: {
        font_family: string;
        base_size_pt: number;
        line_height: number;
        color: string;
        weight: number;
    };
    paragraph: {
        indent_left_mm: number;
        indent_right_mm: number;
        first_line_mm: number;
        space_before_pt: number;
        space_after_pt: number;
        align: 'left' | 'justify' | 'center' | 'right';
    };
    colors: Record<
        | 'primary'
        | 'accent'
        | 'muted'
        | 'table_header_bg'
        | 'table_header_text'
        | 'total_bg'
        | 'total_text',
        string
    >;
    header: {
        show: boolean;
        logo_asset_id: number | null;
        logo_height_mm: number;
        logo_position: 'left' | 'center' | 'right';
        height_mm: number;
        brand_text: string;
    };
    footer: {
        show: boolean;
        text: string;
        page_numbers: boolean;
        distance_mm: number;
    };
    background: {
        asset_id: number | null;
        apply_to: 'all_pages' | 'first_page';
        fit: 'contain' | 'cover' | 'stretch';
        opacity: number;
        position: 'center' | 'top' | 'bottom' | 'left' | 'right';
        safe_area_mm: number;
    };
    tables: {
        font_size_pt: number;
        padding_mm: number;
        border_color: string;
        border_width_px: number;
        header_style: 'solid' | 'light' | 'none';
        repeat_header: boolean;
        avoid_row_break: boolean;
        zebra: boolean;
    };
    signatures: {
        show: boolean;
        line_width_mm: number;
        gap_mm: number;
        position: 'left' | 'center' | 'right' | 'split';
        blocks: { label: string }[];
    };
    sections: {
        spacing_mm: number;
        title_size_pt: number;
        title_color: string;
        divider: boolean;
        visibles: Record<string, boolean>;
    };
    content: {
        titulo: string;
        subtitulo: string;
        leyenda: string;
        nota: string;
    };
};

export type VersionPlantillaAdministrativa = {
    id: number;
    version: number;
    estado: 'borrador' | 'activa' | 'archivada';
    motor: string;
    motor_etiqueta: string;
    notas: string | null;
    hash: string;
    creado_por: string | null;
    creado_en: string;
    activado_por: string | null;
    activado_en: string | null;
    documentos_generados: number;
};

export type RecursoDocumento = {
    id: number;
    nombre: string;
    tipo: string;
    es_fondo: boolean;
    es_logo: boolean;
    url: string;
};
