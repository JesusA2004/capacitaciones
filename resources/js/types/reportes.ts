export type SesionProximaItem = {
    id: number;
    titulo: string;
    fecha_inicio: string;
    duracion_minutos: number;
    leccion: { id: number; titulo: string } | null;
};

export type ResumenCumplimiento = {
    total_asignaciones: number;
    completadas: number;
    vencidas: number;
    porcentaje_cumplimiento: number;
};

export type CumplimientoSucursalItem = {
    sucursal_id: number;
    sucursal: string;
    total: number;
    completadas: number;
    porcentaje: number;
};

export type ColaboradorCumplimientoItem = {
    id: number;
    name: string;
    apellidos: string | null;
    sucursal_principal: { id: number; nombre: string } | null;
    departamento: { id: number; nombre: string } | null;
    asignaciones_total: number;
    asignaciones_completadas: number;
    asignaciones_vencidas: number;
};
