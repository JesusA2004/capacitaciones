/**
 * Rotación de personal cargada desde el Excel de RH
 * (App\Http\Controllers\Reportes\RotacionPersonalController).
 */

export type RotacionMes = {
    clave: string;
    anio: number;
    mes: number;
    etiqueta: string;
    sucursales: number;
    plantilla_inicial: number;
    altas: number;
    bajas: number;
    bajas_voluntarias: number | null;
    bajas_involuntarias: number | null;
    plantilla_final: number;
    indice: number | null;
};

export type RotacionSucursalResumen = {
    sucursal_id: number;
    sucursal: string;
    meses: number;
    altas: number;
    bajas: number;
    plantilla_final: number;
    promedio_mensual: number | null;
    rotacion_acumulada: number | null;
};

export type RotacionTablero = {
    ventana: { desde: string; hasta: string };
    meses: RotacionMes[];
    por_sucursal: RotacionSucursalResumen[];
    totales: {
        altas: number;
        bajas: number;
        plantilla_final: number | null;
        promedio_mensual: number | null;
        rotacion_acumulada: number | null;
        meses_con_datos: number;
    };
};

export type EstadoCargaRotacion = 'analizada' | 'aplicada' | 'descartada';

export type NivelHallazgoRotacion = 'error' | 'advertencia' | 'informativo';

export type RotacionCargaResumen = {
    id: number;
    nombre_archivo: string;
    estado: EstadoCargaRotacion;
    estado_etiqueta: string;
    periodos_detectados: number;
    periodos_nuevos: number;
    periodos_reemplazados: number;
    periodos_sin_cambios: number;
    hallazgos: number;
    subido_por: string;
    creado_en: string | null;
};

export type RotacionPeriodoValores = {
    plantilla_inicial: number;
    altas: number;
    bajas: number;
    bajas_voluntarias: number | null;
    bajas_involuntarias: number | null;
    plantilla_final: number;
    indice: number | null;
    indice_excel: number | null;
};

export type RotacionPeriodoPrevio = RotacionPeriodoValores & {
    sucursal_id: number;
    sucursal: string;
    sucursal_excel: string;
    hoja: string;
    anio: number;
    mes: number;
    accion: 'nuevo' | 'reemplaza' | 'sin_cambios';
    accion_etiqueta: string | null;
    anterior: RotacionPeriodoValores | null;
};

export type RotacionHallazgo = {
    nivel: NivelHallazgoRotacion;
    nivel_etiqueta: string | null;
    hoja: string;
    celda: string | null;
    sucursal: string | null;
    mensaje: string;
};

export type RotacionCargaDetalle = RotacionCargaResumen & {
    aplicado_por: string | null;
    aplicado_en: string | null;
    puede_aplicar: boolean;
    resumen: {
        hojas?: string[];
        anios?: number[];
        sucursales?: string[];
        sucursales_sin_catalogo?: string[];
        por_nivel?: Partial<Record<NivelHallazgoRotacion, number>>;
    };
    periodos: RotacionPeriodoPrevio[];
    hallazgos: RotacionHallazgo[];
};
