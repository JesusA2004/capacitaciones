export type ColaboradorExpedienteItem = {
    id: number;
    name: string;
    apellidos: string | null;
    numero_empleado: string | null;
    foto_path: string | null;
    estatus: string;
    empresa: { id: number; nombre: string } | null;
    sucursal: { id: number; nombre: string; empresa_id: number | null } | null;
    departamento: { id: number; nombre: string } | null;
    puesto: { id: number; nombre: string } | null;
    expediente_porcentaje: number;
    documentos_pendientes: number;
    actualizado_en: string | null;
};

export type ExpedienteColaborador = {
    id: number;
    name: string;
    apellidos: string | null;
    numero_empleado: string | null;
    email: string;
    telefono: string | null;
    foto_path: string | null;
    estatus: string;
    fecha_ingreso: string | null;
    empresa: { id: number; nombre: string } | null;
    sucursal: { id: number; nombre: string } | null;
    departamento: { id: number; nombre: string } | null;
    puesto: { id: number; nombre: string } | null;
    jefe: { id: number; name: string; apellidos: string | null } | null;
    fecha_nacimiento: string | null;
    curp: string | null;
    rfc: string | null;
    nss: string | null;
    domicilio: string | null;
    correo_personal: string | null;
    contacto_emergencia_nombre: string | null;
    contacto_emergencia_telefono: string | null;
};

export type ResumenExpediente = {
    porcentaje: number;
    requeridos_total: number;
    requeridos_aprobados: number;
    pendientes: number;
    rechazados: number;
};

export type DocumentoExpedienteInfo = {
    id: number;
    status: string;
    version: number;
    original_name: string;
    comments: string | null;
    rejection_reason: string | null;
    subido_por: string | null;
    revisado_por: string | null;
    reviewed_at: string | null;
    created_at: string | null;
};

export type DocumentoExpedienteItem = {
    tipo: { id: number; nombre: string; clave: string; requerido: boolean };
    documento: DocumentoExpedienteInfo | null;
};
