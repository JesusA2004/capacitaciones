import type { OpcionSimple } from './administracion';

export type OpcionEnum = {
    value: string;
    etiqueta: string;
};

/**
 * Fila 100% informativa del listado de Vacantes: una combinación
 * (sucursal, puesto) con HeadcountTarget vigente, nunca un registro que RH
 * captura o mueve a mano — ver docs/HEADCOUNT_Y_VACANTES.md y
 * App\Http\Controllers\Rh\VacanteController::filasPlantilla().
 */
/**
 * Una vacante REAL (App\Services\Vacantes\VacantesListadoService): qué
 * puesto falta y dónde, con la plantilla de ese par como contexto.
 */
export type VacanteItem = {
    id: number;
    puesto_id: number | null;
    puesto: string | null;
    sucursal_id: number | null;
    sucursal: string | null;
    departamento: string | null;
    estado: string;
    estado_etiqueta: string;
    motivo: string;
    generada_automaticamente: boolean;
    fecha_apertura: string;
    dias_abierta: number;
    plazas_requeridas: number;
    plazas_disponibles: number;
    candidatos_activos: number;
    candidatos_total: number;
    sueldo_mensual: number | null;
    plantilla_autorizada: number | null;
    plantilla_actual: number;
    faltantes_reales: number | null;
};

export type VacantesKpis = {
    vacantes_abiertas: number;
    plazas_disponibles: number;
    vacantes_automaticas: number;
    vacantes_manuales: number;
    en_reclutamiento: number;
    canceladas: number;
    candidatos_activos: number;
    dias_promedio_abierta: number;
    costo_mensual: number;
};

export type CandidatosKpis = {
    recibidos_periodo: number;
    en_proceso: number;
    finalistas: number;
    contratados_periodo: number;
    /** Razón 0..1 (contratados_periodo / recibidos_periodo) — formatear como porcentaje en la UI. */
    tasa_conversion: number;
    tiempo_promedio_contratacion_dias: number | null;
    /** Los 3 KPIs de costo solo llegan si el módulo de campañas de reclutamiento ya existe en el backend. */
    gasto_reclutamiento_periodo?: number;
    costo_por_candidato?: number;
    costo_por_contratacion?: number;
};

export type SeguimientoResumen = {
    id: number;
    tipo: string;
    nota: string | null;
    estado_nuevo: string | null;
    fecha: string;
    registrado_por: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
};

export type CandidatoItem = {
    id: number;
    empresa: OpcionSimple | null;
    sucursal_id: number | null;
    sucursal: OpcionSimple | null;
    departamento: OpcionSimple | null;
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
    /** Último seguimiento (nota, llamada, cambio de estado...), el que sea más reciente. */
    ultimo_seguimiento: SeguimientoResumen | null;
    /** Último cambio de fase registrado — created_at si el candidato todavía no tiene ninguno. */
    ultimo_cambio_estado: SeguimientoResumen | null;
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
    fuentes?: OpcionEnum[];
    vacantes?: { id: number; puesto_id: number | null }[];
    tiposSeguimiento?: OpcionEnum[];
    transicionesPermitidas?: Record<string, string[]>;
    colaboradores?: {
        id: number;
        name: string;
        apellidos: string | null;
        puesto_id: number | null;
    }[];
};
