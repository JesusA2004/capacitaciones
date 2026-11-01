export type ModoCorreccion =
    | 'directo'
    | 'con_vigencia'
    | 'solo_rectificacion'
    | 'nueva_version'
    | 'no_corregible';

export type DominioCorreccion =
    | 'salario'
    | 'laboral'
    | 'personal'
    | 'contratacion'
    | 'incapacidad'
    | 'recibo_nomina'
    | 'finiquito'
    | 'agenda';

export interface CampoCorreccion {
    campo: string;
    etiqueta: string;
    tipo:
        | 'texto'
        | 'area'
        | 'fecha'
        | 'fecha_hora'
        | 'moneda'
        | 'numero'
        | 'entero'
        | 'seleccion';
    modo: ModoCorreccion;
    modo_etiqueta: string;
    valor: string | null;
    legible: string | null;
    editable: boolean;
    explicacion: string | null;
    opciones: { valor: string; etiqueta: string }[];
}

export interface DescripcionCorreccion {
    dominio: DominioCorreccion;
    dominio_etiqueta: string;
    sujeto_id: number;
    sujeto: string;
    huella: string;
    clave_idempotencia: string;
    tipos_cambio: { valor: string; etiqueta: string; descripcion: string }[];
    campos: CampoCorreccion[];
}

export interface AfectadoCorreccion {
    tipo: string;
    etiqueta: string;
    detalle: string;
    accion:
        | 'se_actualiza'
        | 'requiere_rectificacion'
        | 'requiere_modificacion_contractual'
        | 'revisar'
        | 'intacto'
        | 'nueva_version';
}

export interface VistaPreviaCorreccion {
    huella: string;
    huella_afectados: string;
    cambios: {
        campo: string;
        etiqueta: string;
        antes: string | null;
        despues: string | null;
    }[];
    afectados: AfectadoCorreccion[];
}

export interface RegistroCorreccion {
    id: number;
    dominio: DominioCorreccion;
    dominio_etiqueta: string;
    version: number;
    modo: ModoCorreccion;
    modo_etiqueta: string;
    tipo: string | null;
    motivo: string;
    vigente_desde: string | null;
    fecha: string;
    actor: string;
    origen: string;
    huella: string;
    afectados: AfectadoCorreccion[];
    campos: {
        campo: string;
        etiqueta: string;
        confidencial: boolean;
        visible: boolean;
        anterior: string | null;
        nuevo: string | null;
    }[];
}
