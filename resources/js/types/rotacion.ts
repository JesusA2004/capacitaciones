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

/*
 * Reportes → Rotación de personal (Reportes/Rotacion/Index,
 * App\Http\Controllers\Reportes\TableroRotacionController). Formas de
 * RotacionAnalisisService, HistoricoRotacionService y HallazgosRotacionService.
 * Ver docs/ROTACION.md.
 */

export type SeccionRotacion =
    'resumen' | 'tendencias' | 'causales' | 'sucursales' | 'historico';

export type PeriodoRotacion = 'acumulado' | 'anio' | 'mes' | 'rango';

export type FuenteRotacion = 'todas' | 'sistema' | 'historico';

export type ClasificacionRotacion =
    'voluntaria' | 'involuntaria' | 'sin_clasificar';

export type RangoAntiguedadRotacion =
    | 'menos_3_meses'
    | 'de_3_a_6_meses'
    | 'de_6_a_12_meses'
    | 'de_1_a_3_anios'
    | 'mas_3_anios'
    | 'sin_fecha';

export type FiltroRapidoRotacion =
    | 'pendientes'
    | 'sin_causal'
    | 'sin_homologar'
    | 'sin_fecha'
    | 'inconsistente'
    | 'temprana'
    | 'estructural';

/** FiltrosRotacion::aArray(): mismos nombres que la query string. */
export type FiltrosRotacionTablero = {
    periodo: PeriodoRotacion;
    anio: number;
    mes: number | null;
    desde: string;
    hasta: string;
    empresa_id: number | null;
    sucursal_id: number | null;
    departamento_id: number | null;
    puesto_id: number | null;
    clasificacion: ClasificacionRotacion | null;
    causal_analitica_id: number | null;
    antiguedad: RangoAntiguedadRotacion | null;
    fuente: FuenteRotacion;
    corte_parcial: boolean;
};

/** Desglose con el que se llegó desde una gráfica (solo acota el histórico). */
export type DesgloseRotacion = {
    seccion: string | null;
    d_mes: string | null;
    d_sucursal: string | null;
    d_causal: string | null;
    d_rango: string | null;
    d_clasificacion: string | null;
    rapido: string | null;
    q: string | null;
    orden: string | null;
    dir: string | null;
};

export type OpcionesRotacion = {
    empresas: { id: number; nombre: string }[];
    sucursales: { id: number; nombre: string; empresa_id: number | null }[];
    departamentos: { id: number; nombre: string }[];
    puestos: { id: number; nombre: string }[];
    clasificaciones: { value: ClasificacionRotacion; label: string }[];
    causales: {
        id: number;
        nombre: string;
        clasificacion: ClasificacionRotacion;
        activo: boolean;
    }[];
    antiguedades: { value: RangoAntiguedadRotacion; label: string }[];
    anios: number[];
};

export type ConteoClasificadoRotacion = {
    bajas: number;
    porcentaje: number | null;
};

export type TempranaRotacion = {
    bajas: number;
    base: number;
    excluidas: number;
    porcentaje: number | null;
};

export type IndicePeriodoRotacion = {
    indice: number | null;
    plantilla_promedio: number | null;
    promedio_indices: number | null;
    meses_con_plantilla: number;
};

export type ComparativoRotacion = {
    desde: string;
    hasta: string;
    total: number;
    delta_total: number;
    indice: number | null;
    delta_indice: number | null;
};

export type ResumenRotacion = {
    total: number;
    meses: number;
    promedio_mensual: number | null;
    voluntarias: ConteoClasificadoRotacion;
    involuntarias: ConteoClasificadoRotacion;
    sin_clasificar: ConteoClasificadoRotacion & {
        sin_desglose: number;
        por_revisar: number;
    };
    no_desglosables: number;
    temprana: TempranaRotacion;
    rotacion: IndicePeriodoRotacion;
    comparativo: ComparativoRotacion | null;
    estructurales: number;
    identidad: boolean;
};

export type MesTendenciaRotacion = {
    periodo: string;
    anio: number;
    mes: number;
    total: number;
    voluntarias: number;
    involuntarias: number;
    sin_clasificar: number;
    no_desglosables: number;
    sin_datos: boolean;
    sin_desglose: number;
    plantilla_promedio: number | null;
    indice: number | null;
    en_curso: boolean;
    corte_parcial: boolean;
    discrepancias: number;
    fuentes: ('individual' | 'agregado')[];
};

export type TendenciaRotacion = {
    meses: MesTendenciaRotacion[];
    promedio: number | null;
    mes_mayor: string | null;
};

export type CausalHomologadaRotacion = {
    id: number | null;
    clave: string;
    nombre: string;
    clasificacion: ClasificacionRotacion | null;
    clasificacion_etiqueta: string;
    es_estructural: boolean;
    bajas: number;
    porcentaje: number | null;
    sucursales: number;
};

export type CausalesRotacionTablero = {
    causales: CausalHomologadaRotacion[];
    base: number;
    sin_desglose: number;
};

export type RangoAntiguedadFila = {
    clave: RangoAntiguedadRotacion;
    etiqueta: string;
    bajas: number;
    porcentaje: number | null;
    temprana: boolean;
};

export type AntiguedadRotacion = {
    rangos: RangoAntiguedadFila[];
    base: number;
    excluidas: number;
    inconsistentes: number;
    antes_90_dias: number;
    antes_6_meses: number;
    primer_anio: number;
    promedio_dias: number | null;
    mediana_dias: number | null;
    sin_desglose: number;
};

export type SucursalRotacionFila = {
    sucursal_id: number;
    sucursal: string;
    empresa: string | null;
    es_corporativo: boolean;
    /** Unidad fuera de la estructura de Mr. Lana (Itzamarketing, Lerom…). */
    unidad_externa?: boolean;
    bajas: number;
    participacion: number | null;
    plantilla_promedio: number | null;
    indice: number | null;
    voluntarias: number;
    involuntarias: number;
    sin_clasificar: number;
    sin_desglose: number;
    causal_principal: string | null;
    temprana: number | null;
    serie: number[];
};

export type ColumnaMatrizRotacion = {
    clave: string;
    causal_analitica_id: number | null;
    nombre: string;
    total: number;
};

export type FilaMatrizRotacion = {
    sucursal_id: number;
    sucursal: string;
    celdas: Record<string, number>;
    total: number;
};

export type MatrizRotacion = {
    filas: FilaMatrizRotacion[];
    columnas: ColumnaMatrizRotacion[];
    total: number;
    maximo: number;
    sin_desglose: number;
};

export type DiscrepanciaRotacion = {
    mes: string;
    sucursal_id: number;
    sucursal: string;
    agregado: number;
    individuales: number;
    diferencia: number;
};

export type CalidadRotacion = {
    sin_causal: number;
    sin_homologar: number;
    sin_fecha_ingreso: number;
    fecha_inconsistente: number;
    posibles_duplicados: number;
    duplicadas_ligadas: number;
    discrepancias: DiscrepanciaRotacion[];
    meses_recortados: string[];
};

export type AccionHallazgoRotacion = {
    etiqueta: string;
    tipo: 'filtro' | 'enlace' | 'seccion';
    href?: string;
    filtros?: Record<string, string | number | null>;
};

export type HallazgoRotacionTablero = {
    clave: string;
    tono: 'alerta' | 'info' | 'peligro' | 'neutro';
    titulo: string;
    detalle: string;
    acciones: AccionHallazgoRotacion[];
};

export type AlertaHistoricoRotacion =
    'sin_causal' | 'sin_homologar' | 'sin_fecha' | 'inconsistente';

export type AccionesHistoricoRotacion = {
    ver_baja: string | null;
    expediente: string | null;
    cierre: string | null;
    finiquito: string | null;
    corregir_causal: boolean;
    completar_fecha: boolean;
    correcciones: number;
};

export type FilaHistoricoRotacion = {
    clave: string;
    fuente: 'sistema' | 'historico';
    fuente_etiqueta: string;
    id: number;
    nombre: string;
    numero_empleado: string | null;
    empresa: string | null;
    sucursal_id: number;
    sucursal: string;
    puesto: string | null;
    departamento: string | null;
    fecha_ingreso: string | null;
    fecha_baja: string;
    antiguedad_dias: number | null;
    rango: RangoAntiguedadRotacion;
    rango_etiqueta: string;
    motivo_original: string | null;
    causal_catalogo: string | null;
    causal_analitica_id: number | null;
    causal: string;
    clasificacion: ClasificacionRotacion;
    clasificacion_etiqueta: string;
    estado: string;
    alertas: AlertaHistoricoRotacion[];
    acciones: AccionesHistoricoRotacion;
};

export type PaginaHistoricoRotacion = {
    data: FilaHistoricoRotacion[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type PosibleDuplicadoRotacion = {
    historica_id: number;
    baja_id: number;
    nombre: string;
    fecha_historica: string;
    fecha_sistema: string;
};

export type CategoriaHomologadaRotacion = {
    id: number;
    clave: string;
    nombre: string;
    clasificacion: ClasificacionRotacion;
    clasificacion_etiqueta: string;
    es_estructural: boolean;
    descripcion: string | null;
    activo: boolean;
    causales_catalogo: number;
};

export type MapeoCausalCatalogoRotacion = {
    id: number;
    nombre: string;
    forma: string;
    causal_analitica_id: number | null;
    bajas: number;
};

export type CatalogoHomologacionRotacion = {
    categorias: CategoriaHomologadaRotacion[];
    mapeo: MapeoCausalCatalogoRotacion[];
};

export type EstadoFilaImportacionHistorico =
    'nuevo' | 'sin_cambio' | 'duplicado_sistema' | 'error';

export type FilaImportacionHistoricoRotacion = {
    fila: number;
    nombre: string;
    numero_empleado: string | null;
    sucursal: string | null;
    fecha_ingreso: string | null;
    fecha_baja: string | null;
    motivo_original: string | null;
    causal: string;
    estado: EstadoFilaImportacionHistorico;
    errores: string[];
    avisos: string[];
};

export type ImportacionHistoricoRotacion = {
    id: number;
    nombre_archivo: string | null;
    estado: string;
    total_filas: number | null;
    filas_aplicadas: number | null;
    filas_revision: number | null;
    filas_sin_cambios: number | null;
    filas_error: number | null;
    detalle: FilaImportacionHistoricoRotacion[] | null;
};

export type MetasRotacionPanel = {
    vigentes: {
        segmento: string;
        etiqueta: string;
        verde_hasta: number | null;
        ambar_hasta: number | null;
    }[];
    historial: {
        id: number;
        segmento: string;
        verde_hasta: number;
        ambar_hasta: number;
        vigente_desde: string;
        motivo: string;
        registrado_por: string | null;
        registrado_en: string | null;
    }[];
};

export type PermisosRotacion = {
    ver_personas: boolean;
    corregir: boolean;
    editar_metas: boolean;
    cargar_historico: boolean;
    ver_historico_excel: boolean;
    exportar_detalle: boolean;
};

export type CorreccionBitacoraRotacion = {
    campo: string;
    antes: string | null;
    despues: string | null;
    comentario: string;
    usuario: string | null;
    fecha: string | null;
};
