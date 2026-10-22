import type {
    ContenidoPeriodoPrueba,
    DefinicionInduccion,
    DefinicionPeriodoPrueba,
    DisenoFormulario,
    InduccionContenido,
    PresentacionFormulario,
} from '@/types';

/**
 * Diseño de los formularios de evaluación: SOLO tokens de la paleta pastel
 * (resources/css/app.css). `primario` es el tono fuerte que toman botones y
 * radios dentro del formulario (los acentos claros usan petróleo para
 * conservar el contraste).
 */
const ACENTOS: Record<
    string,
    { fondo: string; texto: string; primario: string }
> = {
    petroleo: {
        fondo: 'var(--pastel-petroleo)',
        texto: 'var(--pastel-superficie)',
        primario: 'var(--pastel-petroleo)',
    },
    esmeralda: {
        fondo: 'var(--pastel-esmeralda)',
        texto: 'var(--pastel-superficie)',
        primario: 'var(--pastel-esmeralda)',
    },
    oro: {
        fondo: 'var(--pastel-oro)',
        texto: 'var(--pastel-texto)',
        primario: 'var(--pastel-bronce)',
    },
    bronce: {
        fondo: 'var(--pastel-bronce)',
        texto: 'var(--pastel-superficie)',
        primario: 'var(--pastel-bronce)',
    },
    salvia: {
        fondo: 'var(--pastel-salvia)',
        texto: 'var(--pastel-petroleo)',
        primario: 'var(--pastel-petroleo)',
    },
    crema: {
        fondo: 'var(--pastel-crema)',
        texto: 'var(--pastel-petroleo)',
        primario: 'var(--pastel-petroleo)',
    },
};

export function tonoAcento(acento: string | undefined | null) {
    return ACENTOS[acento ?? ''] ?? ACENTOS.petroleo;
}

/**
 * Variables CSS del formulario: `--acento`, `--acento-texto` y el
 * `--primary`/`--ring` local, para que botones y radios usen el acento.
 */
export function estiloAcento(
    diseno: Pick<DisenoFormulario, 'acento'> | null | undefined,
): Record<string, string> {
    const tono = tonoAcento(diseno?.acento);

    return {
        '--acento': tono.fondo,
        '--acento-texto': tono.texto,
        '--primary': tono.primario,
        '--ring': tono.primario,
    };
}

/** Textos por defecto de cada sección (los mismos que el servidor). */
export const SECCIONES_INDUCCION = {
    a: {
        titulo: 'Opción múltiple',
        descripcion: 'Elige la respuesta correcta.',
    },
    b: {
        titulo: 'Verdadero o falso',
        descripcion: 'Marca si cada afirmación es verdadera o falsa.',
    },
    c: {
        titulo: 'Relaciona columnas',
        descripcion: 'Elige la descripción que corresponde a cada concepto.',
    },
    d: {
        titulo: 'Casos prácticos',
        descripcion:
            'Responde con tus propias palabras. Los califica Recursos Humanos.',
    },
    e: {
        titulo: 'Tu opinión sobre la inducción',
        descripcion: 'No califica: nos ayuda a mejorar.',
    },
} as const;

export function letraDe(indice: number): string {
    return String.fromCharCode(65 + indice);
}

export function sumaPuntos(lista: { puntos: number }[]): number {
    return lista.reduce((s, p) => s + (Number(p.puntos) || 0), 0);
}

/** Total de puntos de una definición de inducción (A + B + C + D). */
export function totalInduccion(d: DefinicionInduccion): number {
    return (
        sumaPuntos(d.seccion_a) +
        sumaPuntos(d.seccion_b) +
        sumaPuntos(d.seccion_c.conceptos) +
        d.seccion_d.reduce((s, c) => s + sumaPuntos(c.rubrica), 0)
    );
}

type Metadatos = {
    id: number | null;
    clave: string;
    version: string;
    codigo: string;
    titulo: string;
    descripcion: string | null;
    diseno: DisenoFormulario;
};

/**
 * Vista previa: el MISMO contenido público que arma el servidor
 * (FormularioEvaluacionService::contenidoPublicoInduccion) a partir del
 * borrador, sin respuestas correctas ni rúbrica.
 */
export function contenidoPublicoInduccion(
    d: DefinicionInduccion,
    meta: Metadatos,
): InduccionContenido {
    const presentacion: PresentacionFormulario = {
        ...meta,
        secciones: d.secciones,
        puntos_secciones: {
            a: sumaPuntos(d.seccion_a),
            b: sumaPuntos(d.seccion_b),
            c: sumaPuntos(d.seccion_c.conceptos),
            d: d.seccion_d.reduce((s, c) => s + sumaPuntos(c.rubrica), 0),
        },
    };

    return {
        version: meta.version,
        instrucciones: {
            calificacion_aprobatoria: d.calificacion_aprobatoria,
            total_puntos: totalInduccion(d),
        },
        seccion_a: d.seccion_a.map((p, indice) => ({
            indice,
            pregunta: p.pregunta,
            opciones: p.opciones,
        })),
        seccion_b: d.seccion_b.map((p, indice) => ({
            indice,
            afirmacion: p.afirmacion,
        })),
        seccion_c: {
            conceptos: d.seccion_c.conceptos.map((c, indice) => ({
                indice,
                concepto: c.concepto,
            })),
            descripciones: d.seccion_c.descripciones,
        },
        seccion_d: d.seccion_d.map((c) => ({
            clave: c.clave,
            puntos_max: sumaPuntos(c.rubrica),
            enunciado: c.enunciado,
        })),
        seccion_e: d.seccion_e,
        formulario: presentacion,
    };
}

export function contenidoPeriodoPrueba(
    d: DefinicionPeriodoPrueba,
    meta: Metadatos,
): ContenidoPeriodoPrueba {
    return {
        ...meta,
        secciones: null,
        puntos_secciones: null,
        criterios: d.criterios,
        filtros: d.filtros,
        escala_etiquetas: d.escala_etiquetas,
        total_maximo: d.criterios.length * 4,
        total_minimo_renovar: d.total_minimo_renovar,
        un_uno_no_renueva: d.un_uno_no_renueva,
        comentario_obligatorio_hasta: d.comentario_obligatorio_hasta,
        comentario_si_filtro: d.comentario_si_filtro,
    };
}

/** Clave nueva para un reactivo agregado en el editor (el servidor la valida). */
export function claveNueva(prefijo: string): string {
    return `${prefijo}_${Math.random().toString(36).slice(2, 8)}`;
}

/** Mueve un elemento de la lista (en su lugar) y devuelve su nuevo índice. */
export function moverEn<T>(lista: T[], indice: number, delta: number): number {
    const destino = indice + delta;

    if (destino < 0 || destino >= lista.length) {
        return indice;
    }

    const [elemento] = lista.splice(indice, 1);
    lista.splice(destino, 0, elemento);

    return destino;
}

/** Inserta una copia profunda justo debajo; `ajustar` cambia claves que deben ser únicas. */
export function duplicarEn<T>(
    lista: T[],
    indice: number,
    ajustar?: (copia: T) => T,
): number {
    const copia = JSON.parse(JSON.stringify(lista[indice])) as T;
    lista.splice(indice + 1, 0, ajustar ? ajustar(copia) : copia);

    return indice + 1;
}
