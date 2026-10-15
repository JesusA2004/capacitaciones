import type { OpcionSimple } from './administracion';
import type { OpcionEnum } from './reclutamiento';

export type CampanaReclutamientoItem = {
    id: number;
    mes: number;
    anio: number;
    canal: string;
    empresa: OpcionSimple | null;
    sucursal_id: number | null;
    sucursal: OpcionSimple | null;
    departamento: OpcionSimple | null;
    puesto_id: number | null;
    puesto: OpcionSimple | null;
    monto: number;
    candidatos_generados: number | null;
    observaciones: string | null;
    nombre: string | null;
    vacante_id: number | null;
    presupuesto: number | string | null;
    sueldo_publicado: number | string | null;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    copy: string | null;
    url: string | null;
    impresiones: number | null;
    clics: number | null;
    responsable_id: number | null;
    responsable: { id: number; name: string; apellidos: string | null } | null;
    adjuntos_lista: { id: number; nombre: string; mime: string; url: string }[];
    creado_por: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    created_at: string;
    /** Lo que produjo la campaña: contratados y cuánto costó cada uno. */
    resultado: {
        candidatos: number;
        /** Pasaron el Filtro RH (llegaron al menos a entrevista). */
        contactados: number;
        entrevistas: number;
        psicometricos: number;
        socioeconomicos: number;
        contratados: number;
        /** Solo si RH capturó el reporte del proveedor. */
        impresiones: number | null;
        clics: number | null;
        costo_por_clic: number | null;
        costo_por_candidato: number | null;
        costo_por_colaborador: number | null;
        /** % de candidatos que terminaron contratados. */
        conversion: number | null;
        /** Días promedio desde el inicio de la campaña hasta cada contratación. */
        dias_cobertura: number | null;
    } | null;
};

/** Totales del periodo filtrado (todas las campañas, no solo la página). */
export type CampanasTotales = {
    gasto: number;
    contratados: number;
    costo_por_colaborador: number | null;
    campanas: number;
};

export type OpcionesCampanas = {
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    departamentos: OpcionSimple[];
    puestos: (OpcionSimple & { departamento_id: number | null })[];
    canales: OpcionEnum[];
    vacantes: { id: number; etiqueta: string }[];
    responsables: { id: number; name: string; apellidos: string | null }[];
};

/** Un filtro ya aplicado, en texto (mismo que sale en el encabezado del PDF/Excel). */
export type FiltroAplicadoReporte = { etiqueta: string; valor: string };

/** Embudo, fases y motivos de un grupo de candidatos (una campaña o todas). */
export type ResumenReporteCampanas = {
    llegaron: number;
    en_proceso: number;
    no_continuaron: number;
    contratados: number;
    laborando: number;
    conversion: number | null;
    costo_por_candidato: number | null;
    gasto_en_no_continuaron: number | null;
    embudo: { etapa: string; total: number; porcentaje: number | null }[];
    detenidos_por_fase: { fase: string; total: number }[];
    en_proceso_por_fase: { fase: string; total: number }[];
    motivos: {
        categoria: string;
        total: number;
        motivos: { motivo: string; total: number }[];
    }[];
};

export type ReporteCampanaFila = ResumenReporteCampanas & {
    id: number;
    nombre: string;
    canal: string;
    vacante: string;
    fecha_inicio: string | null;
    fecha_fin: string | null;
    estado: string;
    responsable: string | null;
    presupuesto: number | null;
    gasto: number;
    impresiones: number | null;
    clics: number | null;
    costo_por_clic: number | null;
    dias_cobertura: number | null;
    costo_por_contratado: number | null;
    candidatos: {
        nombre: string;
        registrado: string | null;
        fase_alcanzada: string;
        estado: string;
        sigue: boolean;
        salio: boolean;
        motivo: string | null;
    }[];
};

export type ReporteCampanasDatos = {
    campanas: ReporteCampanaFila[];
    totales: ResumenReporteCampanas & {
        campanas: number;
        gasto: number;
        presupuesto: number;
        costo_por_contratado: number | null;
    };
    filtros_aplicados: FiltroAplicadoReporte[];
    periodo: string;
    /** Qué fecha se filtró, explicado para el usuario. */
    criterio: string;
};

/** Filtros del reporte tal como viajan en la query string. */
export type FiltrosReporteCampanas = {
    desde: string;
    hasta: string;
    criterio_fecha: string;
    campanas: string[];
    canales: string[];
    estados: string[];
    fases: string[];
    empresa_id: string;
    sucursal_id: string;
    departamento_id: string;
    puesto_id: string;
    vacante_id: string;
    responsable_id: string;
};

export type OpcionesReporteCampanas = {
    campanas: { id: number; etiqueta: string }[];
    canales: OpcionEnum[];
    estados: OpcionEnum[];
    criterios: (OpcionEnum & { explicacion: string })[];
    fases: OpcionEnum[];
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    departamentos: OpcionSimple[];
    puestos: (OpcionSimple & { departamento_id: number | null })[];
    vacantes: { id: number; etiqueta: string }[];
    responsables: { id: number; nombre: string }[];
};
