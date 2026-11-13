/**
 * Solicitud de empleo + Estudio socioeconómico (perfil de ingreso).
 * Contrato del backend: App\Services\Incorporacion\SolicitudEmpleo\
 * PerfilIngresoService::estado() (docs/SOLICITUD_EMPLEO_Y_SOCIOECONOMICO.md).
 */

export type TipoCampoSolicitud =
    | 'texto'
    | 'texto_largo'
    | 'entero'
    | 'moneda'
    | 'booleano'
    | 'select'
    | 'multiselect'
    | 'fecha'
    | 'mes';

export type ValorSolicitud = string | number | boolean | string[] | null;

export type OpcionSolicitud = { value: string; label: string };

export type CampoSolicitud = {
    campo: string;
    etiqueta: string;
    tipo: TipoCampoSolicitud;
    opciones: OpcionSolicitud[];
    valor: ValorSolicitud;
    /** Texto legible de un dato personal (domicilio, estado civil…). */
    valor_legible: string | null;
    sugerencia: ValorSolicitud;
    requerido: boolean;
    requerido_si: { campo: string; operador: string; valor: unknown } | null;
    permite_no_aplica: boolean;
    no_aplica: boolean;
    bloqueado: boolean;
    origen: 'datos_personales' | 'solicitud';
    documentos: string[];
    min: number | null;
    max: number | null;
    ayuda: string | null;
    /*
     * Solo en el estado de la PROPIA persona
     * (DatosPersonalesSolicitudService::decorar).
     */
    /** Ya lo envió y Recursos Humanos lo está revisando. */
    en_revision?: boolean;
    /** Valor que envió (legible), mientras RH lo revisa. */
    propuesto?: string | null;
    /** El mismo valor sin formato (para evaluar condiciones). */
    propuesto_valor?: string | null;
    /** Solicitud en la que viaja ese dato. */
    solicitud_id?: number | null;
    /** RH le pidió corregir esa solicitud: le toca responder. */
    requiere_respuesta?: boolean;
    /** Falta, pero lo completa Recursos Humanos (no se le pide). */
    solo_rh?: boolean;
    /** Teclado sugerido en el celular. */
    teclado?: 'tel' | 'numeric' | 'email' | null;
};

export type CampoListaSolicitud = {
    campo: string;
    etiqueta: string;
    tipo: TipoCampoSolicitud;
    requerido: boolean;
    opciones: OpcionSolicitud[];
    min: number | null;
    max: number | null;
};

export type ListaSolicitud = {
    clave: string;
    etiqueta: string;
    singular: string;
    minimo: number;
    maximo: number;
    bandera_vacia: string | null;
    etiqueta_vacia: string | null;
    vacia: boolean;
    campos: CampoListaSolicitud[];
    filas: Record<string, ValorSolicitud>[];
    bloqueada: boolean;
    /** Renglones ya registrados: se muestran, no se editan ni se quitan. */
    fijas?: number;
};

export type SeccionSolicitud = {
    clave: string;
    titulo: string;
    descripcion: string;
    completa: boolean;
    porcentaje: number;
    faltantes: string[];
    guardada_en: string | null;
    origen: 'app' | 'rh' | null;
    campos: CampoSolicitud[];
    lista: ListaSolicitud | null;
};

/** Sección vista por la propia persona: qué le falta a ELLA. */
export type SeccionMiSolicitud = SeccionSolicitud & {
    /** Datos que la persona todavía debe llenar aquí (uno por renglón). */
    por_llenar: string[];
    /** Datos personales que ya envió y RH revisa (para ella, ya llenados). */
    en_revision: string[];
    /** Lo que falta y completa Recursos Humanos. */
    de_rh: string[];
    /** true = a la persona ya no le falta nada en esta sección. */
    lista_propia: boolean;
};

/**
 * Avance de la persona en su solicitud (fuente única: la misma cifra en
 * «Mis pendientes», el encabezado y cada paso).
 */
export type AvanceMiSolicitud = {
    por_llenar: number;
    en_revision: number;
    secciones_pendientes: number;
    total: number;
    porcentaje: number;
    /** A la persona ya no le falta nada (puede haber datos en revisión). */
    listo: boolean;
    /** Todo está en el expediente: RH ya puede generar los documentos. */
    completo: boolean;
    /** «Te faltan 6 datos en 2 secciones». */
    frase: string;
};

export type DocumentoCompletitudSolicitud = {
    clave: string;
    nombre: string;
    completo: boolean;
    faltantes: string[];
    mensaje: string;
};

/**
 * «Fecha de emisión» que RH elige para la Solicitud de empleo y para el
 * Estudio socioeconómico (independientes). Fechas en AAAA-MM-DD.
 */
export type EmisionDocumentoIngreso = {
    clave: 'solicitud_empleo' | 'estudio_socioeconomico';
    nombre: string;
    /** La que se imprimirá: la elegida o la de hoy. */
    fecha: string;
    /** false = sin elegir: sale con la fecha del día en que se genere. */
    elegida: boolean;
    minimo: string;
    maximo: string;
    fecha_ingreso: string | null;
    /** Aviso no bloqueante (p. ej. emisión posterior al ingreso). */
    aviso: string | null;
    /** Fecha con la que se imprimió el documento vigente ya generado. */
    generado_con: string | null;
    /** Ya generado con otra fecha: la nueva sale al regenerarlo. */
    desactualizado: boolean;
};

export type EstadoSolicitudEmpleo = {
    /** Solo en las respuestas de RH (web y API v1). */
    emision?: EmisionDocumentoIngreso[];
    secciones: SeccionSolicitud[];
    completitud: {
        completo: boolean;
        porcentaje: number;
        total: number;
        completos: number;
        documentos: Record<string, DocumentoCompletitudSolicitud>;
    };
};
