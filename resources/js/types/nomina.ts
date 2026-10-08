/**
 * Lotes de recibos de nómina (App\Services\Nomina\LoteNominaService):
 * PREPARAR → REVISAR → EMITIR.
 */
export type EstadoLoteNomina = 'borrador' | 'preparado' | 'emitido' | 'cancelado';

export type IncidenciaLote = {
    numero_empleado: string | null;
    colaborador: string | null;
    motivo: string;
    fila?: number | null;
};

export type LoteNomina = {
    id: number;
    folio: string;
    periodicidad: 'semanal' | 'quincenal';
    periodicidad_etiqueta: string;
    etiqueta: string;
    periodo_inicio: string;
    periodo_fin: string;
    fecha_pago: string;
    numero_nomina: number | null;
    origen: 'sueldos' | 'importacion';
    archivo_nombre: string | null;
    estado: EstadoLoteNomina;
    estado_etiqueta: string;
    esperados: number;
    preparados: number;
    por_emitir: number;
    emitidos: number;
    errores: IncidenciaLote[];
    advertencias: IncidenciaLote[];
    total_errores: number;
    total_advertencias: number;
    total_percepciones: string;
    total_deducciones: string;
    total_neto: string;
    creado_por: string | null;
    creado_en: string;
    emitido_por: string | null;
    emitido_at: string | null;
    cancelado_por: string | null;
    cancelado_at: string | null;
    motivo_cancelacion: string | null;
};

export type ReciboLote = {
    id: number | null;
    folio: string | null;
    colaborador_id: number | null;
    numero_empleado: string | null;
    nombre: string;
    sucursal: string | null;
    puesto: string | null;
    total_percepciones: string | null;
    total_deducciones: string | null;
    neto: string | null;
    estado: string;
    estado_etiqueta: string;
    advertencias: string[];
    revision: 'correcto' | 'advertencia' | 'error';
    clave_error?: number;
};
