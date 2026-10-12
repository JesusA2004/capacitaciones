/**
 * Evaluación oficial del Programa de Inducción (FO-RH-EI, 30 puntos) —
 * contenido público (sin clave de respuestas ni rúbrica) y detalle de casos
 * prácticos para RH. Ver App\Services\Onboarding\EvaluacionInduccionService.
 */
export type InduccionContenido = {
    version: string;
    instrucciones: { calificacion_aprobatoria: number; total_puntos: number };
    seccion_a: { indice: number; pregunta: string; opciones: string[] }[];
    seccion_b: { indice: number; afirmacion: string }[];
    seccion_c: {
        conceptos: { indice: number; concepto: string }[];
        descripciones: Record<string, string>;
    };
    seccion_d: { clave: string; puntos_max: number; enunciado: string }[];
    seccion_e: {
        aspectos: { clave: string; texto: string }[];
        escala_min: number;
        escala_max: number;
    };
};

export type InduccionRespuestas = {
    a: Record<number, number>;
    b: Record<number, boolean>;
    c: Record<number, string>;
    d: Record<string, string>;
};

export type InduccionOpinion = {
    claridad: number | null;
    conocimiento_puesto: number | null;
    bienvenida: number | null;
    tema_reforzar: string | null;
};

/**
 * Un capítulo navegable de la presentación de Inducción Mr. Lana — ver
 * OnboardingModulo::contenidoEstructurado() y
 * database/seeders/InduccionMrLanaModuloSeeder.php (contenido real del PDF).
 */
export type BloqueCapituloInduccion =
    | { tipo: 'parrafo'; titulo?: string; texto: string }
    | { tipo: 'destacado'; texto: string }
    | { tipo: 'datos'; items: { etiqueta: string; valor: string }[] }
    | {
          tipo: 'lista';
          titulo?: string;
          texto?: string;
          destacado?: boolean;
          items: string[];
      }
    | {
          tipo: 'tarjetas';
          titulo?: string;
          items: { titulo: string; texto: string; nota?: string }[];
      }
    | {
          tipo: 'organigrama_dinamico';
          vista: 'corporativo' | 'comercial' | 'sucursal';
          titulo: string;
          nota?: string;
      }
    | { tipo: 'cierre'; titulo: string; texto: string };

export type CapituloInduccion = {
    clave: string;
    numero: number;
    titulo: string;
    subtitulo?: string;
    bloques: BloqueCapituloInduccion[];
};

/** Solo para RH: trae enunciado, respuesta del colaborador y rúbrica oficial. */
export type InduccionCasoPractico = {
    clave: string;
    enunciado: string;
    puntos_max: number;
    respuesta: string;
    rubrica: { clave: string; puntos: number; criterio: string }[];
    desglose_previo: { clave: string; puntos: number }[] | null;
};
