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
