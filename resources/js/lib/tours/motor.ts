/**
 * Reglas puras del motor del tour guiado (sin DOM, sin Vue, sin imports en
 * tiempo de ejecución) para poder probarlas con `node --test`
 * (tests/js/motor-tour.test.mjs). `useTourGuiado` y `TourGuiado.vue` las
 * aplican.
 */

/** Máximo que se espera a que aparezca el elemento de un paso. */
export const ESPERA_ELEMENTO_MS = 1500;
/** Espera mínima aun cuando la pantalla ya lleva rato cargada. */
export const ESPERA_MINIMA_MS = 300;
/** Holgura del hueco resaltado alrededor del elemento. */
export const PADDING_SPOTLIGHT = 8;
/** Separación entre el hueco resaltado y la caja de la guía. */
export const SEPARACION = 16;
/** Distancia mínima de la caja a los bordes de la pantalla. */
export const BORDE = 12;
/** Ancho mínimo de la caja cuando la normal no cabe a un lado del objetivo. */
export const ANCHO_ANGOSTO = 300;
/** Debajo de este ancho la guía es una hoja inferior (o superior). */
export const ANCHO_MOVIL = 640;

/** Estados del motor (máquina de estados, no una colección de booleanos). */
export type EstadoTour =
    'idle' | 'navigating' | 'waiting-target' | 'showing' | 'finishing';

export type PasoMotor = {
    selector?: string;
    titulo: string;
    opcional?: boolean;
};

export type Rect = { top: number; left: number; width: number; height: number };
export type Tamano = { ancho: number; alto: number };
export type Lado = 'abajo' | 'arriba' | 'derecha' | 'izquierda';

export type Posicion = {
    /** centrado: sin objetivo; flotante: junto al objetivo; hoja-*: móvil. */
    modo: 'centrado' | 'flotante' | 'hoja-abajo' | 'hoja-arriba';
    lado: Lado | null;
    top: number;
    left: number;
    ancho: number;
    /** true solo si no hubo forma de evitar tapar parte del objetivo. */
    tapaObjetivo: boolean;
};

/**
 * Qué hacer cuando el elemento de un paso no apareció a tiempo: un paso
 * opcional se omite; uno obligatorio se muestra CENTRADO, sin spotlight
 * (el texto sigue explicando el paso). Nunca se queda esperando.
 */
export function decisionSinElemento(paso: PasoMotor): 'omitir' | 'centrado' {
    return paso.opcional ? 'omitir' : 'centrado';
}

/**
 * Cuánto esperar el elemento de un paso. El presupuesto completo
 * (`maximoMs`) cuenta desde que la PANTALLA terminó de cargar, no desde
 * cada paso: si en una misma pantalla faltan varios elementos seguidos, el
 * segundo y los siguientes ya no esperan otra vez el máximo cada uno.
 */
export function esperaParaElemento(
    llegadaAPantallaMs: number,
    ahoraMs: number,
    maximoMs: number = ESPERA_ELEMENTO_MS,
    minimoMs: number = ESPERA_MINIMA_MS,
): number {
    const transcurrido = Math.max(0, ahoraMs - llegadaAPantallaMs);

    return Math.max(minimoMs, maximoMs - transcurrido);
}

/** Mensaje de desarrollo que identifica tour, paso y selector faltante. */
export function avisoSelectorFaltante(
    tourId: string,
    indice: number,
    paso: PasoMotor,
): string {
    return `[tour "${tourId}"] paso ${indice + 1} («${paso.titulo}»): no se encontró el selector ${paso.selector ?? '(sin selector)'} — ${
        decisionSinElemento(paso) === 'omitir'
            ? 'se omite (opcional)'
            : 'se muestra centrado sin spotlight'
    }.`;
}

/** true si dos rectángulos se enciman (compartir solo un borde no cuenta). */
export function intersects(a: Rect, b: Rect): boolean {
    return (
        a.left < b.left + b.width &&
        b.left < a.left + a.width &&
        a.top < b.top + b.height &&
        b.top < a.top + a.height
    );
}

function areaEncimada(a: Rect, b: Rect): number {
    const ancho =
        Math.min(a.left + a.width, b.left + b.width) - Math.max(a.left, b.left);
    const alto =
        Math.min(a.top + a.height, b.top + b.height) - Math.max(a.top, b.top);

    return ancho > 0 && alto > 0 ? ancho * alto : 0;
}

function limitar(valor: number, minimo: number, maximo: number): number {
    return Math.max(minimo, Math.min(valor, Math.max(minimo, maximo)));
}

/** Hueco resaltado: el objetivo con su holgura. */
export function rectSpotlight(
    objetivo: Rect,
    padding = PADDING_SPOTLIGHT,
): Rect {
    return {
        top: objetivo.top - padding,
        left: objetivo.left - padding,
        width: objetivo.width + padding * 2,
        height: objetivo.height + padding * 2,
    };
}

/**
 * Dónde poner la caja de la guía para que NUNCA tape el elemento que
 * explica (si hay forma de evitarlo):
 *
 *  - sin objetivo → centrada;
 *  - móvil → hoja inferior; si el objetivo quedaría debajo de la hoja,
 *    hoja superior;
 *  - escritorio → abajo o arriba (el de más espacio) si la caja cabe
 *    completa; si no, derecha o izquierda (el de más espacio); la caja se
 *    desliza a lo largo del objetivo sin salirse de la pantalla;
 *  - si en ningún lado cabe completa → la ubicación (lados y esquinas) que
 *    menos tape al objetivo.
 */
export function calcularPosicion(
    objetivo: Rect | null,
    caja: Tamano,
    pantalla: Tamano,
): Posicion {
    const esMovil = pantalla.ancho < ANCHO_MOVIL;
    const ancho = Math.min(
        esMovil ? pantalla.ancho - BORDE * 2 : caja.ancho,
        pantalla.ancho - BORDE * 2,
    );
    const alto = Math.min(caja.alto, pantalla.alto - BORDE * 2);

    if (objetivo === null) {
        return {
            modo: 'centrado',
            lado: null,
            left: (pantalla.ancho - ancho) / 2,
            top: limitar(
                (pantalla.alto - alto) / 2,
                BORDE,
                pantalla.alto - alto - BORDE,
            ),
            ancho,
            tapaObjetivo: false,
        };
    }

    const hueco = rectSpotlight(objetivo);

    if (esMovil) {
        const abajo: Rect = {
            top: pantalla.alto - alto - BORDE,
            left: BORDE,
            width: ancho,
            height: alto,
        };
        const arriba: Rect = {
            top: BORDE,
            left: BORDE,
            width: ancho,
            height: alto,
        };
        const tapaAbajo = areaEncimada(abajo, hueco);
        const tapaArriba = areaEncimada(arriba, hueco);
        const usarArriba = tapaAbajo > 0 && tapaArriba < tapaAbajo;
        const hoja = usarArriba ? arriba : abajo;

        return {
            modo: usarArriba ? 'hoja-arriba' : 'hoja-abajo',
            lado: null,
            top: hoja.top,
            left: hoja.left,
            ancho,
            tapaObjetivo: (usarArriba ? tapaArriba : tapaAbajo) > 0,
        };
    }

    const centroX = hueco.left + hueco.width / 2;
    const centroY = hueco.top + hueco.height / 2;
    const leftCentrado = limitar(
        centroX - ancho / 2,
        BORDE,
        pantalla.ancho - ancho - BORDE,
    );
    const topCentrado = limitar(
        centroY - alto / 2,
        BORDE,
        pantalla.alto - alto - BORDE,
    );

    const candidatos: { lado: Lado; espacio: number; rect: Rect }[] = [
        {
            lado: 'abajo',
            espacio: pantalla.alto - (hueco.top + hueco.height),
            rect: {
                top: hueco.top + hueco.height + SEPARACION,
                left: leftCentrado,
                width: ancho,
                height: alto,
            },
        },
        {
            lado: 'arriba',
            espacio: hueco.top,
            rect: {
                top: hueco.top - SEPARACION - alto,
                left: leftCentrado,
                width: ancho,
                height: alto,
            },
        },
        {
            lado: 'derecha',
            espacio: pantalla.ancho - (hueco.left + hueco.width),
            rect: {
                top: topCentrado,
                left: hueco.left + hueco.width + SEPARACION,
                width: ancho,
                height: alto,
            },
        },
        {
            lado: 'izquierda',
            espacio: hueco.left,
            rect: {
                top: topCentrado,
                left: hueco.left - SEPARACION - ancho,
                width: ancho,
                height: alto,
            },
        },
    ];

    const dentro = (r: Rect): boolean =>
        r.top >= BORDE - 0.5 &&
        r.left >= BORDE - 0.5 &&
        r.top + r.height <= pantalla.alto - BORDE + 0.5 &&
        r.left + r.width <= pantalla.ancho - BORDE + 0.5;

    // Orden abajo, arriba, derecha, izquierda: primero los lados verticales
    // que caben (el de más espacio); solo si ninguno cabe, los laterales.
    const vertical = (c: { lado: Lado }): number =>
        c.lado === 'abajo' || c.lado === 'arriba' ? 0 : 1;
    const validos = candidatos
        .filter((c) => dentro(c.rect) && !intersects(c.rect, hueco))
        .sort((a, b) => vertical(a) - vertical(b) || b.espacio - a.espacio);

    if (validos.length > 0) {
        const elegido = validos[0];

        return {
            modo: 'flotante',
            lado: elegido.lado,
            top: elegido.rect.top,
            left: elegido.rect.left,
            ancho,
            tapaObjetivo: false,
        };
    }

    // Antes de tapar nada: una caja más angosta quizá sí cabe a un lado
    // (p. ej. junto a un calendario alto con una columna al costado).
    if (caja.ancho > ANCHO_ANGOSTO) {
        const angosta = calcularPosicion(
            objetivo,
            { ...caja, ancho: ANCHO_ANGOSTO },
            pantalla,
        );

        if (!angosta.tapaObjetivo) {
            return angosta;
        }
    }

    // Nada cabe completo: lados ajustados a la pantalla y las 4 esquinas;
    // gana el que menos tape al objetivo.
    const ajustar = (r: Rect): Rect => ({
        ...r,
        top: limitar(r.top, BORDE, pantalla.alto - alto - BORDE),
        left: limitar(r.left, BORDE, pantalla.ancho - ancho - BORDE),
    });
    const esquinas: { lado: Lado; rect: Rect }[] = [
        {
            lado: 'abajo',
            rect: {
                top: pantalla.alto - alto - BORDE,
                left: pantalla.ancho - ancho - BORDE,
                width: ancho,
                height: alto,
            },
        },
        {
            lado: 'abajo',
            rect: {
                top: pantalla.alto - alto - BORDE,
                left: BORDE,
                width: ancho,
                height: alto,
            },
        },
        {
            lado: 'arriba',
            rect: {
                top: BORDE,
                left: pantalla.ancho - ancho - BORDE,
                width: ancho,
                height: alto,
            },
        },
        {
            lado: 'arriba',
            rect: { top: BORDE, left: BORDE, width: ancho, height: alto },
        },
    ];
    const opciones = [
        ...candidatos.map((c) => ({ lado: c.lado, rect: ajustar(c.rect) })),
        ...esquinas,
    ]
        .map((o) => ({ ...o, tapa: areaEncimada(o.rect, hueco) }))
        .sort((a, b) => a.tapa - b.tapa);
    const mejor = opciones[0];

    return {
        modo: 'flotante',
        lado: mejor.lado,
        top: mejor.rect.top,
        left: mejor.rect.left,
        ancho,
        tapaObjetivo: mejor.tapa > 0,
    };
}
