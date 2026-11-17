/**
 * Fechas laborales del colaborador (docs/FECHAS_LABORALES.md):
 * App\Services\Colaboradores\FechasLaboralesService::resumen().
 */
export type OrigenPeriodoPrueba = 'especifico' | 'puesto' | 'sin_definir';

export interface PeriodoPruebaEfectivo {
    cantidad: number | null;
    unidad: 'meses' | 'dias' | null;
    origen: OrigenPeriodoPrueba;
    etiqueta: string;
    puesto_meses: number | null;
    puesto_etiqueta: string | null;
}

export interface AlertaFechasLaborales {
    codigo:
        | 'contrato_desfasado'
        | 'contrato_firmado_desfasado'
        | 'evaluacion_desfasada'
        | 'sin_contrato'
        | 'faltan_fechas';
    nivel: 'critico' | 'alto' | 'medio';
    titulo: string;
    detalle: string;
    accion: 'corregir_contrato' | 'ir_contratos' | 'editar_fechas' | null;
    accion_etiqueta: string | null;
    contrato_id: number | null;
    url: string | null;
}

export interface SeguimientoFechas {
    dias: number;
    nombre: string;
    es_final: boolean;
    fecha: string | null;
    fecha_calculada: string | null;
    evaluacion_id: number | null;
    estado: string;
    estado_etiqueta: string;
}

export interface CapturadasFechasLaborales {
    fecha_ingreso: string | null;
    periodo_prueba_inicio: string | null;
    fecha_induccion: string | null;
    fecha_alta_imss: string | null;
    periodo_prueba_cantidad: number | null;
    periodo_prueba_unidad: 'meses' | 'dias' | null;
}

export interface ResumenFechasLaborales {
    colaborador_id: number;
    puede_editar: boolean;
    hoy: string;
    capturadas: CapturadasFechasLaborales;
    periodo: PeriodoPruebaEfectivo;
    induccion: {
        fecha: string | null;
        origen: 'capturada' | 'sistema' | 'falta';
    };
    inicio_capacitacion: string | null;
    inicio_capacitacion_origen: 'capturada' | 'ingreso' | 'falta';
    fin_periodo: string | null;
    evaluacion_desde: string | null;
    dias_transcurridos: number | null;
    dias_restantes: number | null;
    contrato: {
        id: number;
        tipo: string;
        tipo_etiqueta: string;
        fecha_inicio: string;
        fecha_fin: string | null;
        firmado: boolean;
        documento_generado: boolean;
    } | null;
    evaluacion_periodo_prueba: {
        id: number;
        estado: string;
        estado_etiqueta: string;
        fecha_limite: string | null;
    } | null;
    seguimientos: SeguimientoFechas[];
    faltantes: { campo: string; etiqueta: string; critico: boolean }[];
    alertas: AlertaFechasLaborales[];
    importado: boolean;
}

export interface FilaCapturaFechas {
    id: number;
    nombre: string;
    numero_empleado: string | null;
    puesto: string | null;
    sucursal: string | null;
    importado: boolean;
    fecha_ingreso: string | null;
    periodo_prueba_inicio: string | null;
    fecha_induccion: string | null;
    fecha_induccion_sistema: string | null;
    fecha_alta_imss: string | null;
    periodo_prueba_cantidad: number | null;
    periodo_prueba_unidad: 'meses' | 'dias' | null;
    periodo: PeriodoPruebaEfectivo;
    fin_periodo: string | null;
    faltantes: string[];
    expediente_url: string;
}
