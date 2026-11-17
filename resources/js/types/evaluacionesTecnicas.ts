/**
 * Evaluaciones técnicas por puesto («Seguimiento 15 / 30 / 60…») — ver
 * App\Services\EvaluacionesTecnicas\* y docs/EVALUACIONES_TECNICAS.md.
 */

import type { PresentacionFormulario } from './formulariosEvaluacion';

export type TipoPreguntaTecnica =
    | 'opcion_multiple'
    | 'casillas'
    | 'verdadero_falso'
    | 'escala'
    | 'respuesta_corta'
    | 'parrafo';

export type OpcionPreguntaTecnica = { clave: string; texto: string };

export type PreguntaTecnica = {
    clave: string;
    tipo: TipoPreguntaTecnica;
    enunciado: string;
    ayuda: string;
    obligatoria: boolean;
    puntos: number;
    opciones: OpcionPreguntaTecnica[];
    /** Solo en la definición completa (RH/editor); nunca a quien contesta. */
    correctas?: string[];
    escala_min: number;
    escala_max: number;
    etiqueta_min: string;
    etiqueta_max: string;
};

export type SeccionTecnica = {
    clave: string;
    titulo: string;
    descripcion: string;
    preguntas: PreguntaTecnica[];
};

/** Definición COMPLETA (con respuestas correctas): solo el editor. */
export type DefinicionTecnica = {
    calificacion_aprobatoria: number;
    mostrar_resultado: boolean;
    secciones: SeccionTecnica[];
    total_preguntas: number;
    total_puntos: number;
};

/** Lo que recibe quien contesta (y la vista previa): sin correctas. */
export type ContenidoFormularioTecnico = Omit<
    PresentacionFormulario,
    'secciones'
> & {
    mostrar_resultado: boolean;
    total_puntos: number;
    total_preguntas: number;
    secciones: SeccionTecnica[];
};

export type RespuestaTecnica = string | string[] | number | null;
export type RespuestasTecnicas = Record<string, RespuestaTecnica>;

export type QuienContestaTecnica = 'colaborador' | 'jefe_directo';

export type EstadoHitoTecnico =
    | 'pendiente'
    | 'vencida'
    | 'aprobada'
    | 'no_aprobada'
    | 'contestada'
    | 'cancelada'
    | 'programada'
    | 'no_generada';

export type FilaEvaluacionTecnica = {
    id: number | null;
    nombre: string;
    dias: number;
    es_final: boolean;
    estado: EstadoHitoTecnico;
    estado_etiqueta: string;
    fecha_programada: string;
    fecha_limite: string;
    dias_restantes: number;
    contesta: QuienContestaTecnica;
    contesta_etiqueta: string;
    calificacion_minima: number;
    calificacion: number | null;
    aprobada: boolean | null;
    contestada_en?: string | null;
    puesto?: string | null;
    evaluador?: string | null;
    colaborador?: {
        id: number;
        nombre: string;
        foto_url: string | null;
        sucursal: string | null;
    } | null;
};

export type DetalleEvaluacionTecnica = FilaEvaluacionTecnica & {
    puntos_obtenidos: number | null;
    puntos_totales: number | null;
    comentarios: string | null;
    contestada_por: string | null;
    motivo_cancelacion: string | null;
    preguntas: {
        seccion: string;
        enunciado: string;
        tipo: TipoPreguntaTecnica;
        respuesta: string | null;
        correcta: string | null;
        obtenidos: number | null;
        maximos: number | null;
        acierto: boolean | null;
    }[];
};

export type TableroEvaluacionesTecnicas = {
    kpis: {
        pendientes: number;
        vencidas: number;
        por_vencer: number;
        contestadas_30: number;
        aprobacion: number | null;
        promedio: number | null;
    };
    por_puesto: {
        id: number;
        nombre: string;
        contestadas: number;
        aprobadas: number;
        aprobacion: number | null;
        promedio: number | null;
    }[];
    por_dia: {
        dias: number;
        nombre: string;
        contestadas: number;
        aprobacion: number | null;
        promedio: number | null;
    }[];
    por_vencer: FilaEvaluacionTecnica[];
    vencidas: FilaEvaluacionTecnica[];
    lista: {
        data: FilaEvaluacionTecnica[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
    };
};

export type FiltrosEvaluacionesTecnicas = {
    buscar: string | null;
    estado: string | null;
    puesto: number | null;
    sucursal: number | null;
    contesta: string | null;
};

export type LineaTiempoTecnica = {
    colaborador: {
        id: number;
        nombre: string;
        foto_url: string | null;
        puesto: string | null;
        sucursal: string | null;
        jefe: string | null;
        fecha_ingreso: string | null;
        dias_desde_ingreso: number | null;
    };
    hitos: FilaEvaluacionTecnica[];
    resumen: { contestadas: number; aprobadas: number; promedio: number | null };
};

export type PasoCalendarioTecnico = {
    id?: number | null;
    dias: number;
    nombre: string;
    plantilla_id: number | null;
    plantilla?: string | null;
    contesta: QuienContestaTecnica;
    contesta_etiqueta?: string;
    calificacion_minima: number;
    dias_para_contestar: number;
    es_final: boolean;
    activo: boolean;
};

export type PuestoCalendarioTecnico = {
    id: number;
    nombre: string;
    departamento: string | null;
    meses_periodo_prueba: number | null;
    colaboradores_activos: number;
    sugerencia: number[];
    pasos: PasoCalendarioTecnico[];
};

export type PlantillaTecnica = {
    id: number;
    nombre: string;
    descripcion: string | null;
    activa: boolean;
    pasos: number;
    version_publicada: number | null;
    tiene_borrador: boolean;
    acento: string | null;
    preguntas: number;
    total_puntos: number;
    actualizado_en: string | null;
};
