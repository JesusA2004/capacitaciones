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
    creado_por: {
        id: number;
        name: string;
        apellidos: string | null;
    } | null;
    created_at: string;
    /** Lo que produjo la campaña: contratados y cuánto costó cada uno. */
    resultado: {
        candidatos: number;
        contratados: number;
        costo_por_colaborador: number | null;
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
};
