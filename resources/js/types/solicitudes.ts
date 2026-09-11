import type { DocumentoGeneradoItem } from './plantillas';

export type TipoSolicitudInterna = {
    value: string;
    label: string;
};

/**
 * Catálogo de tipos con las reglas de formulario, tal como lo arma
 * SolicitudesService::tiposConFormulario() — usado por el formulario "Nueva
 * solicitud" del colaborador (campos condicionales por tipo) y por la app
 * móvil.
 */
export type TipoSolicitudInternaFormulario = {
    clave: string;
    nombre: string;
    requiere_fechas: boolean;
    requiere_horario: boolean;
    requiere_dias: boolean;
    requiere_monto: boolean;
    requiere_colaborador_objetivo: boolean;
    requiere_motivo: boolean;
    permite_adjuntos: boolean;
};

export type ColaboradorParaBaja = {
    id: number;
    name: string;
    apellidos: string | null;
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
    colaborador_objetivo_id: number | null;
    tipo: string;
    estado: string;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    dias_solicitados: number | null;
    monto_solicitado: string | null;
    plazo_meses: number | null;
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
    colaboradorObjetivo?: UsuarioResumen | null;
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
