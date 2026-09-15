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
    motivo_cancelacion: string | null;
    fecha_apertura: string;
    fecha_estimada_cobertura: string | null;
    observaciones: string | null;
    candidatos_count: number;
    generada_automaticamente: boolean;
    plazas_requeridas: number;
    plazas_cubiertas: number;
    plazas_disponibles: number;
    plantilla_autorizada: number | null;
    plantilla_actual: number | null;
    faltantes_reales: number | null;
};

export type VacantesKpis = {
    vacantes_abiertas: number;
    plazas_disponibles: number;
    vacantes_automaticas: number;
    vacantes_manuales: number;
    en_reclutamiento: number;
    cubiertas_este_mes: number;
    canceladas: number;
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
    alta_digital: { id: number; estado: string } | null;
    incorporacion_invitacion: { id: number; estado: string } | null;
};

export type CandidatoTimelineEtapa = {
    clave: string;
    titulo: string;
    estado: 'completado' | 'actual' | 'pendiente' | 'descartado';
    fecha: string | null;
    responsable: string | null;
    accion: string | null;
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
