/**
 * Formularios de evaluación configurables (inducción FO-RH-EI y periodo de
 * prueba FO-RH-EPP-001) — ver App\Services\Evaluaciones\FormularioEvaluacionService.
 */

export type ClaveFormularioEvaluacion = 'induccion' | 'periodo_prueba';

export type ColorAcentoFormulario =
    'petroleo' | 'esmeralda' | 'oro' | 'bronce' | 'salvia' | 'crema';

export type EstiloEncabezadoFormulario = 'franja' | 'solido' | 'degradado';

export type DisenoFormulario = {
    acento: ColorAcentoFormulario | string;
    estilo_encabezado: EstiloEncabezadoFormulario | string;
    banner_url: string | null;
    banner_path?: string | null;
};

export type SeccionInduccionClave = 'a' | 'b' | 'c' | 'd' | 'e';

export type TextosSecciones = Record<
    SeccionInduccionClave,
    { titulo: string; descripcion: string }
>;

/** Título, diseño y textos para dibujar el formulario (sin respuestas). */
export type PresentacionFormulario = {
    id: number | null;
    clave: ClaveFormularioEvaluacion | string;
    version: string;
    codigo: string;
    titulo: string;
    descripcion: string | null;
    diseno: DisenoFormulario;
    secciones: TextosSecciones | null;
    puntos_secciones: Record<'a' | 'b' | 'c' | 'd', number> | null;
};

/** Definición COMPLETA de la inducción (con respuestas): solo RH/editor. */
export type DefinicionInduccion = {
    total_puntos: number;
    calificacion_aprobatoria: number;
    calificacion_sobresaliente: number;
    dias_habiles_reevaluacion: number;
    secciones: TextosSecciones;
    seccion_a: {
        pregunta: string;
        opciones: string[];
        correcta: number;
        puntos: number;
    }[];
    seccion_b: { afirmacion: string; correcto: boolean; puntos: number }[];
    seccion_c: {
        conceptos: {
            concepto: string;
            letra_correcta: string;
            puntos: number;
        }[];
        descripciones: Record<string, string>;
    };
    seccion_d: {
        clave: string;
        puntos_max: number;
        enunciado: string;
        rubrica: { clave: string; puntos: number; criterio: string }[];
    }[];
    seccion_e: {
        aspectos: { clave: string; texto: string }[];
        escala_min: number;
        escala_max: number;
        escala_etiquetas: Record<string, string>;
        pregunta_abierta: { clave: string; texto: string };
    };
};

export type ReactivoPeriodoPrueba = {
    clave: string;
    etiqueta: string;
    descripcion?: string;
};

export type DefinicionPeriodoPrueba = {
    escala_min: number;
    escala_max: number;
    escala_etiquetas: Record<string, string>;
    criterios: ReactivoPeriodoPrueba[];
    filtros: ReactivoPeriodoPrueba[];
    total_maximo: number;
    total_minimo_renovar: number;
    un_uno_no_renueva: boolean;
    comentario_obligatorio_hasta: number;
    comentario_si_filtro: boolean;
};

/** Lo que recibe el formulario de captura del jefe (y su vista previa). */
export type ContenidoPeriodoPrueba = PresentacionFormulario &
    Omit<DefinicionPeriodoPrueba, 'escala_min' | 'escala_max'>;

export type FormularioEvaluacionDetalle = {
    id: number;
    clave: ClaveFormularioEvaluacion;
    version: number;
    etiqueta_version: string;
    codigo: string;
    titulo: string;
    descripcion: string | null;
    estado: 'borrador' | 'publicado' | 'archivado';
    estado_etiqueta: string;
    diseno: DisenoFormulario;
    publicado_en: string | null;
    publicado_por: string | null;
    actualizado_en: string | null;
    actualizado_por: string | null;
    definicion: DefinicionInduccion | DefinicionPeriodoPrueba | null;
    problemas: string[];
    evaluaciones?: number;
};

export type ResumenFormularioEvaluacion = {
    clave: ClaveFormularioEvaluacion;
    etiqueta: string;
    descripcion_modulo: string;
    publicado: FormularioEvaluacionDetalle;
    borrador: FormularioEvaluacionDetalle | null;
    total_versiones: number;
    resumen: { preguntas: number; total_puntos: number; aprobatoria: number };
};

export type OpcionesDisenoFormulario = {
    acentos: { value: ColorAcentoFormulario; label: string }[];
    estilos: { value: EstiloEncabezadoFormulario; label: string }[];
};
