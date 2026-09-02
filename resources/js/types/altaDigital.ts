import type { OpcionSimple } from './administracion';

export type AltaDigitalDocumentoItem = {
    id: number;
    document_type_id: number;
    original_name: string;
    mime: string | null;
    size: number | null;
    tipo: { id: number; nombre: string; clave: string } | null;
};

type PersonaBasica = { id: number; name: string; apellidos: string | null };

export type AltaDigitalItem = {
    id: number;
    candidato: {
        id: number;
        nombre: string;
        apellidos: string | null;
        correo?: string | null;
    } | null;
    vacante: { id: number; puesto_id: number | null } | null;
    empresa: OpcionSimple | null;
    sucursal: OpcionSimple | null;
    departamento: OpcionSimple | null;
    puesto: OpcionSimple | null;
    token: string;
    token_expira_en: string | null;
    estado: string;
    nombre: string | null;
    apellidos: string | null;
    telefono: string | null;
    correo: string | null;
    fecha_nacimiento: string | null;
    curp: string | null;
    rfc: string | null;
    nss: string | null;
    domicilio: string | null;
    contacto_emergencia_nombre: string | null;
    contacto_emergencia_telefono: string | null;
    fecha_ingreso_propuesta: string | null;
    foto_original_name: string | null;
    tiene_foto: boolean;
    tiene_firma: boolean;
    aviso_privacidad_aceptado: boolean;
    consentimiento_datos_aceptado: boolean;
    enviada_en: string | null;
    revisado_por: PersonaBasica | null;
    aprobado_por: PersonaBasica | null;
    motivo_rechazo: string | null;
    comentarios: string | null;
    colaborador: PersonaBasica | null;
    documentos: AltaDigitalDocumentoItem[];
    created_at: string;
};

export type DocumentoRequeridoAlta = {
    id: number;
    nombre: string;
    clave: string;
    requerido: boolean;
};
