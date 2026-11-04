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

export type DocumentoCompletitudSolicitud = {
    clave: string;
    nombre: string;
    completo: boolean;
    faltantes: string[];
    mensaje: string;
};

export type EstadoSolicitudEmpleo = {
    secciones: SeccionSolicitud[];
    completitud: {
        completo: boolean;
        porcentaje: number;
        total: number;
        completos: number;
        documentos: Record<string, DocumentoCompletitudSolicitud>;
    };
};
