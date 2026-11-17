/**
 * Nine Box de Seguimiento (docs/NINE_BOX.md): forma que arma
 * App\Services\NineBox\NineBoxService.
 */

export type NivelNineBox = 'bajo' | 'medio' | 'alto';

export type TipoPeriodoNineBox = 'mes' | 'trimestre';

export type ClaveCuadranteNineBox =
    | 'estrella'
    | 'alto_potencial'
    | 'enigma'
    | 'profesional_clave'
    | 'nucleo'
    | 'dilema'
    | 'confiable'
    | 'efectivo'
    | 'bajo_desempeno';

export type TonoCuadranteNineBox =
    | 'estrella'
    | 'fuerte'
    | 'info'
    | 'base'
    | 'calido'
    | 'alerta'
    | 'riesgo';

export type CuadranteNineBox = {
    clave: ClaveCuadranteNineBox;
    etiqueta: string;
    descripcion: string;
    accion: string;
    tono: TonoCuadranteNineBox;
    desempeno: NivelNineBox;
    potencial: NivelNineBox;
};

export type PeriodoNineBox = {
    tipo: TipoPeriodoNineBox;
    clave: string;
    etiqueta: string;
    descripcion: string;
    inicio: string | null;
    fin: string | null;
    meses_ids: number[];
    meses: string[];
    completo: boolean;
};

export type ComponenteDesempeno = {
    clave: 'colocacion' | 'recuperacion' | 'mora';
    etiqueta: string;
    valor: number | null;
    formato: 'moneda' | 'porcentaje';
    puntaje: number | null;
    peso: number;
    metodo: 'meta' | 'percentil' | 'rango';
};

export type CalibracionVigente = {
    nivel: NivelNineBox;
    etiqueta: string;
    comentario: string;
    periodo: string;
    /** Viene de un periodo anterior (sigue vigente hasta recalibrar). */
    heredada: boolean;
    capturada_por: string | null;
    capturada_en: string | null;
};

export type PersonaNineBox = {
    /** Id del gestor en Reportes. */
    id: number;
    colaborador_id: number | null;
    nombre: string;
    iniciales: string;
    foto_url: string | null;
    puesto: string | null;
    sucursal_id: number | null;
    sucursal: string | null;
    branch: string | null;
    vinculado: boolean;
    estado: 'ubicado' | 'sin_vincular' | 'sin_potencial';
    cuadrante: ClaveCuadranteNineBox | null;
    desempeno: {
        puntaje: number | null;
        nivel: NivelNineBox | null;
        componentes: ComponenteDesempeno[];
    };
    potencial: {
        puntaje: number | null;
        nivel: NivelNineBox | null;
        evaluaciones: number | null;
        calibracion: CalibracionVigente | null;
    };
    kpis: {
        colocacion: number;
        operaciones: number;
        recuperacion: number;
        cartera: number | null;
        vencida: number | null;
        mora: number | null;
    };
    semanas: {
        semana_id: number;
        etiqueta: string;
        mes: string;
        colocacion: number;
        recuperacion: number;
    }[];
    puede_calibrar: boolean;
};

export type ValoresNineBox = {
    desempeno: {
        pesos: { colocacion: number; recuperacion: number; mora: number };
        meta_colocacion_mensual: number | null;
        meta_recuperacion_mensual: number | null;
        mora_excelente: number;
        mora_critica: number;
    };
    potencial: {
        pesos: { evaluaciones: number; calibracion: number };
        ventana_evaluaciones_meses: number;
        valores_calibracion: { bajo: number; medio: number; alto: number };
    };
    cortes: {
        desempeno: { medio: number; alto: number };
        potencial: { medio: number; alto: number };
    };
};

export type ConfiguracionNineBox = {
    valores: ValoresNineBox;
    version: number | null;
    guardada_por: string | null;
    guardada_en: string | null;
    motivo: string | null;
    historial: {
        id: number;
        guardada_por: string | null;
        guardada_en: string | null;
        motivo: string | null;
    }[];
};

export type MatrizNineBox = {
    estado: 'ok' | 'sin_datos';
    periodos: { meses: PeriodoNineBox[]; trimestres: PeriodoNineBox[] };
    periodo: PeriodoNineBox | null;
    configuracion: ValoresNineBox;
    cuadrantes: CuadranteNineBox[];
    personas: PersonaNineBox[];
    conteos: Record<ClaveCuadranteNineBox, number>;
    resumen: {
        total: number;
        ubicados: number;
        sin_vincular: number;
        sin_potencial: number;
        calibrados: number;
    };
    opciones_sucursal: { value: number; label: string }[];
};

export type FiltrosNineBox = {
    tipo: TipoPeriodoNineBox;
    periodo: string | null;
    sucursal: number | null;
};

export type EvaluacionTecnicaNineBox = {
    id: number;
    /** «Seguimiento 15», «Seguimiento 30»… */
    nombre: string;
    dias: number;
    es_final: boolean;
    fecha_programada: string;
    vencida: boolean;
    estado: 'pendiente' | 'contestada' | 'cancelada';
    estado_etiqueta: string;
    calificacion: number | null;
    aprobada: boolean | null;
    contestada_en: string | null;
};

export type CalibracionHistorial = {
    id: number;
    periodo: string;
    periodo_tipo: TipoPeriodoNineBox;
    nivel: NivelNineBox;
    etiqueta: string;
    comentario: string;
    capturada_por: string | null;
    capturada_en: string | null;
};

export type DetallePersonaNineBox = {
    evaluaciones: EvaluacionTecnicaNineBox[];
    calibraciones: CalibracionHistorial[];
};
