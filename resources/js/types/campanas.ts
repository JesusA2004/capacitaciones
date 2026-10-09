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
