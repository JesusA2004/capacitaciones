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
/** Campo del formulario dinámico (p. ej. el permiso). */
export type CampoSolicitudFormulario = {
    name: string;
    type: 'date' | 'dates' | 'number' | 'text' | 'time' | 'opciones' | 'select';
    required: boolean;
    label: string;
    min?: number;
    max?: number;
    ayuda?: string;
    opciones?: { value: string; label: string }[];
    /** Solo se muestra si `campo` tiene alguno de `valores`. */
    mostrar_si?: { campo: string; valores: string[] };
};

/** Actualización de datos: dato, valor en el expediente y valor propuesto. */
export type DatoPropuesto = {
    campo: string;
    etiqueta: string;
    actual: string | null;
    propuesto: string;
};

/** Resumen del permiso (formato oficial liberado solo tras RH). */
export type PermisoResumen = {
    tipo: string | null;
    tipo_etiqueta: string | null;
    goce: string | null;
    goce_etiqueta: string | null;
    causal: string | null;
    causal_etiqueta: string | null;
    hora_salida: string | null;
    hora_entrada: string | null;
    dias: number | null;
    autorizado_por_rh: boolean;
    autorizado_por: string | null;
    autorizado_en: string | null;
    pdf_disponible: boolean;
};

export type TipoSolicitudInternaFormulario = {
    clave: string;
    nombre: string;
    /** Aparece como tarjeta en «Nueva solicitud» (permisos, vacaciones, préstamos). */
    en_catalogo: boolean;
    descripcion: string;
    campos: CampoSolicitudFormulario[];
    /** Cómo se capturan las fechas (App\Enums\ModoFechasSolicitud). */
    modo_fechas:
        'duracion' | 'dias_especificos' | 'horario' | 'fecha_unica' | 'ninguna';
    modo_fechas_etiqueta: string;
    /** Vacaciones: días de la semana que no se pueden elegir (0 = domingo). */
    dias_no_seleccionables: number[];
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
    /** Texto en español (SolicitudInternaHistorial::accion_etiqueta). */
    accion_etiqueta: string;
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
    vacaciones_pendientes_pago: string;
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

/**
 * Una fila del desglose editable del finiquito (automática o capturada por
 * RH) — misma fuente que usa el PDF (App\Services\Finiquitos\FiniquitoService::desglose()).
 */
export type FiniquitoDesgloseItem = {
    id: number | null;
    /** Clave del formato oficial (001 Sueldo pendiente… 101 ISR…). */
    clave?: string;
    /** Concepto automático ajustable (sueldo_pendiente, isr_retenido…). */
    concepto_clave?: string | null;
    dias?: number | null;
    valor_calculado?: number | null;
    ajustado?: boolean;
    ajuste?: { motivo: string; usuario: string | null; fecha: string | null; valor_calculado: number; valor_final: number } | null;
    editable?: boolean;
    concepto: string;
    tipo: 'percepcion' | 'deduccion' | string;
    cantidad: number;
    importe: number;
    observaciones: string | null;
    origen: 'automatico' | 'manual' | string;
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
    /** Foto (miniatura) del colaborador que solicita, si tiene. */
    foto_url?: string | null;
    colaborador?:
        | (UsuarioResumen & {
              puesto?: { id: number; nombre: string } | null;
              sucursal_principal_id?: number | null;
              sucursal_principal?: { id: number; nombre: string } | null;
          })
        | null;
    usuario?:
        | (UsuarioResumen & {
              colaborador?: {
                  puesto?: { id: number; nombre: string } | null;
                  sucursal_principal?: { id: number; nombre: string } | null;
              } | null;
          })
        | null;
    /** Sujeto real de la baja (fuente de verdad nueva, objetivo_colaborador_id). */
    objetivo_colaborador?: UsuarioResumen | null;
    /** Legacy: apunta a `users` (colaborador_objetivo_id). */
    colaborador_objetivo?: UsuarioResumen | null;
    revisado_por?: UsuarioResumen | null;
    sucursal?: { id: number; nombre: string } | null;
    documentos?: SolicitudInternaDocumentoItem[];
    documentos_count?: number;
    documentos_generados?: DocumentoGeneradoItem[];
    official_format_generations?: OfficialFormatGenerationItem[];
    historial?: SolicitudInternaHistorialItem[];
    finiquito_calculo?: FiniquitoCalculoItem | null;
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
