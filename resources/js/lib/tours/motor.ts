/**
 * Reglas puras del motor del tour guiado (sin DOM, sin Vue, sin imports en
 * tiempo de ejecución) para poder probarlas con `node --test`
 * (tests/js/motor-tour.test.mjs). `useTourGuiado` las aplica.
 */

/** Máximo que se espera a que aparezca el elemento de un paso. */
export const ESPERA_ELEMENTO_MS = 2500;
/** Espera mínima aun cuando la pantalla ya lleva rato cargada. */
export const ESPERA_MINIMA_MS = 300;

export type PasoMotor = {
    selector?: string;
    titulo: string;
    opcional?: boolean;
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
 * segundo y los siguientes ya no esperan otra vez 2.5 s cada uno (eso era
 * lo que hacía parecer congelada la guía de Cumpleaños).
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
