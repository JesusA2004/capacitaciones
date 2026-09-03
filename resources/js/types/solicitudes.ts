import type { DocumentoGeneradoItem } from './plantillas';

export type TipoSolicitudInterna = {
    value: string;
    label: string;
};

type UsuarioResumen = {
    id: number;
    name: string;
    apellidos: string | null;
};

export type SolicitudInternaDocumentoItem = {
    id: number;
    original_name: string;
    subido_por?: UsuarioResumen | null;
    created_at: string;
};

export type SolicitudInternaHistorialItem = {
    id: number;
    accion: string;
    comentario: string | null;
    usuario?: UsuarioResumen | null;
    created_at: string;
};

export type SolicitudInternaItem = {
    id: number;
    folio: string;
    user_id: number;
    tipo: string;
    estado: string;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    motivo: string;
    observaciones: string | null;
    motivo_rechazo: string | null;
    revisado_en: string | null;
    created_at: string;
    usuario?:
        | (UsuarioResumen & {
              puesto?: { id: number; nombre: string } | null;
              sucursal_principal_id?: number | null;
              sucursalPrincipal?: { id: number; nombre: string } | null;
          })
        | null;
    revisado_por?: UsuarioResumen | null;
    documentos?: SolicitudInternaDocumentoItem[];
    documentos_generados?: DocumentoGeneradoItem[];
    historial?: SolicitudInternaHistorialItem[];
};

export type OpcionesSolicitudes = {
    empresas: { id: number; nombre: string }[];
    sucursales: { id: number; nombre: string; empresa_id: number | null }[];
    departamentos?: { id: number; nombre: string }[];
    puestos?: { id: number; nombre: string }[];
    responsables?: { id: number; name: string; apellidos: string | null }[];
};
