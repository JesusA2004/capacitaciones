export type OpcionCatalogoReporte = {
    value: string;
    label: string;
};

export type GrupoCatalogoReportes = {
    grupo: string;
    opciones: OpcionCatalogoReporte[];
};

export type ResultadoReporteRh = {
    titulo: string;
    columnas: string[];
    filas: (string | number | null)[][];
};

export type FiltrosReporteRh = {
    empresa_id?: string;
    sucursal_id?: string;
    departamento_id?: string;
    puesto_id?: string;
    colaborador_id?: string;
    fecha_inicio?: string;
    fecha_fin?: string;
    estado?: string;
    tipo_solicitud?: string;
    tipo_documento?: string;
};

export type OpcionesReportesRh = {
    empresas: { id: number; nombre: string }[];
    sucursales: { id: number; nombre: string; empresa_id: number | null }[];
    departamentos: { id: number; nombre: string }[];
    puestos: { id: number; nombre: string }[];
};
