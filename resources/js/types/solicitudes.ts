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

export type FiniquitoCalculoItem = {
    id: number;
    estado: string;
    fecha_calculo: string;
    fecha_ingreso: string;
    fecha_baja: string;
    sueldo_mensual: string;
    sueldo_diario: string;
    antiguedad_anios: number;
    antiguedad_meses: number;
    dias_trabajados_periodo: number;
    vacaciones_pendientes: number;
    prima_vacacional: string;
    aguinaldo_proporcional: string;
    sueldo_pendiente: string;
    indemnizacion: string;
    bonos_extra: string;
    descuentos: string;
    adeudos: string;
    otros_conceptos: Record<string, number> | null;
    total_calculado: string;
    total_ajustado: string;
    comentarios_ajuste: string | null;
    documento_generado_path: string | null;
    documento_firmado_path: string | null;
    revisado_por?: UsuarioResumen | null;
};

export type FiniquitoPermisos = {
    puedeCalcular: boolean;
    puedeRevisar: boolean;
    puedeSubirFirmado: boolean;
    puedeOmitirRevision: boolean;
    usaFormatoOficial: boolean;
};

/**
 * Documento oficial automático de la solicitud (config/solicitudes.php +
 * App\Services\Solicitudes\SolicitudFormatoOficialService) — distinto de
 * documentos_generados (plantillas DOCX manuales/opcionales).
 */
export type OfficialFormatGenerationItem = {
    id: number;
    generated_name: string;
    status: 'generado' | 'firmado';
    signed_name: string | null;
    signed_uploaded_at: string | null;
    firmado_por?: UsuarioResumen | null;
    formato?: { id: number; nombre: string } | null;
    created_at: string;
};

export type DocumentoOficialEsperado = {
    id: number | null;
    nombre: string;
    configurado: boolean;
    requiereFirma: boolean;
    puedeConfigurar: boolean;
};

export type SolicitudInternaItem = {
    id: number;
    folio: string;
    user_id: number;
    colaborador_objetivo_id: number | null;
    fecha_efectiva: string | null;
    tipo_baja: string | null;
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
              sucursal_principal?: { id: number; nombre: string } | null;
          })
        | null;
    colaborador_objetivo?: UsuarioResumen | null;
    revisado_por?: UsuarioResumen | null;
    sucursal?: { id: number; nombre: string } | null;
    documentos?: SolicitudInternaDocumentoItem[];
    documentos_count?: number;
    documentos_generados?: DocumentoGeneradoItem[];
    official_format_generations?: OfficialFormatGenerationItem[];
    historial?: SolicitudInternaHistorialItem[];
    finiquitoCalculo?: FiniquitoCalculoItem | null;
};

/** Fila resumida para la tab "Solicitudes" del expediente (Rh\ExpedienteController). */
export type SolicitudExpedienteItem = {
    id: number;
    folio: string;
    tipo: string;
    tipo_etiqueta: string;
    estado: string;
    motivo: string;
    created_at: string;
};

export type OpcionesSolicitudes = {
    empresas: { id: number; nombre: string }[];
    sucursales: { id: number; nombre: string; empresa_id: number | null }[];
    departamentos?: { id: number; nombre: string }[];
    puestos?: { id: number; nombre: string }[];
    responsables?: { id: number; name: string; apellidos: string | null }[];
};
