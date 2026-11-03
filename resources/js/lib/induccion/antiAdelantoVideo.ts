/**
 * Regla anti-adelanto del video de la Inducción (refuerzo del cliente; lo que
 * cuenta como VISTO lo decide el heartbeat en el servidor).
 *
 * Se valida la POSICIÓN reproducida, nunca la carga: el máximo visto solo
 * crece con reproducción continua (lecturas de `timeupdate` cercanas entre
 * sí) y nunca con un seek. Un seek —barra, teclado o lo que sea— más allá de
 * ese máximo se regresa. Los micro-saltos que hace el propio reproductor al
 * recuperarse de un buffering (nudges de hls.js, huecos de buffer) caen dentro
 * de la tolerancia y no cuentan como adelanto.
 *
 *   npm run test:js
 */

/** Margen para los micro-saltos del reproductor al salir de un buffering. */
export const TOLERANCIA_SALTO_SEGUNDOS = 3;

/**
 * Avance máximo entre dos `timeupdate` seguidos que todavía es reproducción
 * normal (el navegador los emite cada ~250 ms; con margen por pestañas lentas).
 */
export const AVANCE_CONTINUO_MAXIMO_SEGUNDOS = 2.5;

/** Hay que regresar el video a `regresarA` (null = la posición es válida). */
export type ResultadoSalto = {
    regresarA: number | null;
    maximoVisto: number;
};

/**
 * El salto de `anterior` a `actual` es reproducción normal (no un seek).
 * `segundosReales` = tiempo de reloj entre ambas lecturas (una pestaña en
 * segundo plano emite `timeupdate` más espaciado: eso no es adelantar).
 */
export function esAvanceContinuo(
    anterior: number,
    actual: number,
    segundosReales = 0,
): boolean {
    const delta = actual - anterior;
    const limite = Math.max(
        AVANCE_CONTINUO_MAXIMO_SEGUNDOS,
        segundosReales + 1,
    );

    return delta >= 0 && delta <= limite;
}

/** Un destino de seek es válido si no pasa de lo ya visto (+ tolerancia). */
export function seekPermitido(destino: number, maximoVisto: number): boolean {
    return destino <= maximoVisto + TOLERANCIA_SALTO_SEGUNDOS;
}

/**
 * Evento `seeking` (o un salto detectado sin él): compara el destino contra lo
 * visto ANTES de tocar el máximo. Retroceder o moverse dentro de lo visto se
 * permite; ir más allá regresa al máximo visto.
 */
export function evaluarSeek(
    destino: number,
    maximoVisto: number,
): ResultadoSalto {
    if (seekPermitido(destino, maximoVisto)) {
        return { regresarA: null, maximoVisto };
    }

    return { regresarA: maximoVisto, maximoVisto };
}

/**
 * Evento `timeupdate`: si el avance es continuo, el máximo visto sube; si es
 * un salto (el navegador no siempre emite `seeking`, p. ej. con controles
 * nativos de iOS), se evalúa como seek.
 */
export function evaluarTimeUpdate(
    anterior: number,
    actual: number,
    maximoVisto: number,
    segundosReales = 0,
): ResultadoSalto {
    if (esAvanceContinuo(anterior, actual, segundosReales)) {
        // El máximo sube a lo más lo que de verdad avanzó la reproducción:
        // encadenar seeks pequeños dentro de la tolerancia no lo «estira».
        const avance = actual - anterior;

        return {
            regresarA: null,
            maximoVisto: Math.max(
                maximoVisto,
                Math.min(actual, maximoVisto + avance),
            ),
        };
    }

    return evaluarSeek(actual, maximoVisto);
}
