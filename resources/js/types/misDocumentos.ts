/**
 * Autoservicio documental del colaborador en la web: lo mismo que la app
 * (IncorporacionService::accionesPropias, DocumentoLaboralConsultaService::aArray,
 * ContratoLaboralService::aArray).
 */

/** Formato que genera PEOPLE (solicitud de empleo, estudio socioeconómico): nunca se sube. */
export type GeneradoPorSistema = {
    estado: 'escaneo' | 'generado' | 'por_generar';
    cumplido: boolean;
    titulo: string | null;
    mensaje: string | null;
    captura: 'registro' | 'rh';
    documento_generado_id: number | null;
};

/** Qué puede hacer el propio colaborador con un tipo documental (por id de tipo). */
export type AccionDocumentoPropio = {
    puede_subir: boolean;
    puede_reemplazar: boolean;
    puede_solicitar_cambio: boolean;
    generado_por_sistema: GeneradoPorSistema | null;
    /** «Fotografía»: la cubre la foto de perfil, no se sube aparte. */
    cubierto_por_foto: boolean;
};

export type EstadoDocumentoLaboral =
    | 'generado'
    | 'pendiente_firma_colaborador'
    | 'firmado_digitalmente'
    | 'pendiente_impresion'
    | 'impreso'
    | 'pendiente_firma_fisica'
    | 'firmado_fisicamente'
    | 'enviado_corporativo'
    | 'recibido_corporativo'
    | 'escaneado'
    | 'archivado';

export type DocumentoLaboralPropio = {
    id: number;
    titulo: string;
    categoria: string | null;
    estado: EstadoDocumentoLaboral | null;
    estado_etiqueta: string | null;
    requiere_firma_digital: boolean;
    requiere_impresion: boolean;
    requiere_firma_fisica: boolean;
    firmado_digital_en: string | null;
    generado_en: string | null;
    version_plantilla: string | null;
    puede_descargar?: boolean;
    puede_firmar: boolean;
    original_fisico: {
        impreso_en: string | null;
        firmado_fisico_en: string | null;
    } | null;
};

export type ContratoPropio = {
    id: number;
    tipo: string;
    tipo_etiqueta: string;
    estado: 'vigente' | 'renovado' | 'terminado' | 'cancelado' | string;
    fecha_inicio: string;
    fecha_fin: string | null;
    dias_para_vencer: number | null;
    documento_id: number | null;
    documento_firmado: boolean;
};
