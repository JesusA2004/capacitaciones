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
};

export type CampanasKpis = {
    gasto_total: number;
    candidatos_generados: number;
    costo_por_candidato: number;
    contratados: number;
    costo_por_contratacion: number;
};

export type OpcionesCampanas = {
    empresas: OpcionSimple[];
    sucursales: (OpcionSimple & { empresa_id: number | null })[];
    departamentos: OpcionSimple[];
    puestos: (OpcionSimple & { departamento_id: number | null })[];
    canales: OpcionEnum[];
};
