import type { OpcionSimple } from './administracion';

export type OpcionEnum = {
    value: string;
    etiqueta: string;
};

export type VacanteItem = {
    id: number;
    empresa: OpcionSimple | null;
    sucursal_id: number | null;
    sucursal: OpcionSimple | null;
    departamento: OpcionSimple | null;
    puesto: OpcionSimple | null;
    gerente_solicitante: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    responsable_rh: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    motivo: string;
    estado: string;
    fecha_apertura: string;
    fecha_estimada_cobertura: string | null;
    observaciones: string | null;
    candidatos_count: number;
};

export type CandidatoItem = {
    id: number;
    empresa: OpcionSimple | null;
    sucursal_id: number | null;
    sucursal: OpcionSimple | null;
    puesto_objetivo: OpcionSimple | null;
    vacante: { id: number; puesto_id: number | null } | null;
    responsable_rh: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    gerente_involucrado: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    nombre: string;
    apellidos: string | null;
    telefono: string | null;
    correo: string | null;
    fuente: string | null;
    tiene_cv: boolean;
    cv_original_name: string | null;
    observaciones: string | null;
    estado: string;
    fecha_entrevista: string | null;
    resultado_entrevista: string | null;
    created_at: string;
};

export type SeguimientoCandidatoItem = {
    id: number;
    tipo: string;
    nota: string | null;
    estado_anterior: string | null;
    estado_nuevo: string | null;
    fecha: string;
    registrado_por: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
};

export type CandidatoDetalle = CandidatoItem & {
    departamento: OpcionSimple | null;
    documentos_solicitados: string | null;
    seguimientos: SeguimientoCandidatoItem[];
};

export type OpcionesReclutamiento = {
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    departamentos: OpcionSimple[];
    puestos: (OpcionSimple & { departamento_id: number | null })[];
    responsables?: { id: number; name: string; apellidos: string | null }[];
    motivos?: OpcionEnum[];
    estados: OpcionEnum[];
    vacantes?: { id: number; puesto_id: number | null }[];
    tiposSeguimiento?: OpcionEnum[];
    colaboradores?: {
        id: number;
        name: string;
        apellidos: string | null;
        puesto_id: number | null;
    }[];
};
