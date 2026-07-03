export type OpcionPreguntaItem = {
    id: number;
    texto: string;
    es_correcta: boolean;
    orden: number;
};

export type PreguntaItem = {
    id: number;
    banco_pregunta_id: number;
    enunciado: string;
    tipo: string;
    puntos: number;
    explicacion: string | null;
    opciones: OpcionPreguntaItem[];
    pivot?: {
        orden: number;
        puntos: number | null;
    };
};

export type BancoPreguntaItem = {
    id: number;
    nombre: string;
    descripcion: string | null;
    creado_por: number;
    preguntas_count?: number;
    preguntas?: PreguntaItem[];
};

export type CuestionarioItem = {
    id: number;
    leccion_id: number;
    titulo: string;
    instrucciones: string | null;
    calificacion_minima: number;
    intentos_maximos: number | null;
    tiempo_limite_minutos: number | null;
    aleatorizar_preguntas: boolean;
    mostrar_retroalimentacion: boolean;
    preguntas?: PreguntaItem[];
};

export type PreguntaParaResolver = {
    id: number;
    enunciado: string;
    tipo: string;
    puntos: number;
    opciones: { id: number; texto: string }[];
};

export type ResultadoIntento = {
    estado: string;
    calificacion: number | null;
    aprobado: boolean | null;
};

export type RetroalimentacionPregunta = {
    pregunta_id: number;
    es_correcta: boolean | null;
    explicacion: string | null;
};

export type IntentoCalificacionItem = {
    id: number;
    numero_intento: number;
    estado: string;
    enviado_en: string | null;
    usuario: { id: number; name: string; apellidos: string | null };
    cuestionario: { id: number; titulo: string; leccion_id: number };
};

export type RespuestaParaCalificar = {
    id: number;
    pregunta_id: number;
    opcion_pregunta_id: number | null;
    opciones_seleccionadas: number[] | null;
    respuesta_texto: string | null;
    es_correcta: boolean | null;
    puntos_obtenidos: number | null;
    pregunta: {
        id: number;
        enunciado: string;
        tipo: string;
        puntos: number;
        opciones: OpcionPreguntaItem[];
    };
};

export type IntentoDetalleCalificacion = {
    id: number;
    numero_intento: number;
    usuario: { id: number; name: string; apellidos: string | null };
    cuestionario: { id: number; titulo: string };
    respuestas: RespuestaParaCalificar[];
};
