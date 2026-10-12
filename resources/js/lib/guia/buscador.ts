import type { ArticuloGuia, PreguntaFrecuente, TerminoGlosario } from './tipos';

/**
 * Búsqueda local de la guía (sin IA ni servidor): normaliza acentos y
 * mayúsculas, quita palabras vacías y puntúa cada artículo por dónde
 * aparece cada palabra. Es una función pura (la prueban tests/js) que
 * recibe el contenido, así este archivo no importa datos ni Vue.
 */

const PALABRAS_VACIAS = new Set([
    'a',
    'al',
    'como',
    'con',
    'cual',
    'cuales',
    'cuando',
    'de',
    'del',
    'donde',
    'el',
    'en',
    'es',
    'esta',
    'hago',
    'hay',
    'la',
    'las',
    'lo',
    'los',
    'me',
    'mi',
    'mis',
    'no',
    'o',
    'para',
    'por',
    'porque',
    'puedo',
    'que',
    'quien',
    'se',
    'si',
    'su',
    'sus',
    'tengo',
    'un',
    'una',
    'y',
    'yo',
]);

export function normalizar(texto: string): string {
    return texto
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .replace(/[^a-z0-9ñ\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

/** Raíz simple: quita plurales y terminaciones verbales comunes. */
function raiz(palabra: string): string {
    if (palabra.length <= 4) {
        return palabra;
    }

    return palabra.replace(
        /(aciones|acion|amente|mente|ciones|cion|es|s|ar|er|ir|o|a|e)$/u,
        '',
    );
}

export function terminos(consulta: string): string[] {
    return normalizar(consulta)
        .split(' ')
        .filter((p) => p.length > 1 && !PALABRAS_VACIAS.has(p))
        .map(raiz);
}

export type ResultadoBusqueda = {
    articulo: ArticuloGuia;
    puntos: number;
    /** Pregunta o frase que mejor coincidió (para mostrarla). */
    coincidencia: string | null;
};

function contiene(texto: string, termino: string): boolean {
    return normalizar(texto)
        .split(' ')
        .some((p) => raiz(p).startsWith(termino) || p.startsWith(termino));
}

/**
 * Artículos que coinciden con la consulta, de mejor a peor. Pesos: pregunta
 * frecuente del artículo > título > resumen > pasos y demás texto.
 */
export function buscarArticulos(
    consulta: string,
    articulos: ArticuloGuia[],
    limite = 8,
): ResultadoBusqueda[] {
    const busqueda = terminos(consulta);

    if (busqueda.length === 0) {
        return [];
    }

    const resultados: ResultadoBusqueda[] = [];

    for (const articulo of articulos) {
        let puntos = 0;
        let coincidencia: string | null = null;
        let mejorPregunta = 0;
        let encontrados = 0;

        for (const pregunta of articulo.preguntas) {
            const aciertos = busqueda.filter((t) =>
                contiene(pregunta, t),
            ).length;

            if (aciertos > mejorPregunta) {
                mejorPregunta = aciertos;
                coincidencia = pregunta;
            }
        }

        const cuerpo = [
            articulo.queEs,
            ...articulo.pasos.flatMap((p) => [p.titulo, p.detalle]),
            ...articulo.despues,
            ...articulo.errores.flatMap((e) => [e.problema, e.solucion]),
            ...(articulo.campos ?? []).flatMap((c) => c.campos),
        ].join(' ');

        for (const termino of busqueda) {
            let encontrado = false;

            if (contiene(articulo.titulo, termino)) {
                puntos += 6;
                encontrado = true;
            }

            if (contiene(articulo.resumen, termino)) {
                puntos += 3;
                encontrado = true;
            }

            if (contiene(cuerpo, termino)) {
                puntos += 1;
                encontrado = true;
            }

            if (encontrado) {
                encontrados++;
            }
        }

        puntos += mejorPregunta * 8;

        // Toda palabra de la búsqueda debe aparecer en algún lado (salvo
        // consultas largas, donde basta la mayoría).
        const minimo =
            busqueda.length <= 2
                ? busqueda.length
                : Math.ceil(busqueda.length * 0.6);

        if (encontrados + mejorPregunta >= minimo && puntos > 0) {
            resultados.push({
                articulo,
                puntos,
                coincidencia: mejorPregunta > 0 ? coincidencia : null,
            });
        }
    }

    return resultados.sort((a, b) => b.puntos - a.puntos).slice(0, limite);
}

export function buscarGlosario(
    consulta: string,
    glosario: TerminoGlosario[],
): TerminoGlosario[] {
    const busqueda = terminos(consulta);

    return busqueda.length === 0
        ? []
        : glosario.filter((t) =>
              busqueda.every((b) =>
                  contiene(`${t.termino} ${t.definicion}`, b),
              ),
          );
}

export function buscarPreguntas(
    consulta: string,
    preguntas: PreguntaFrecuente[],
): PreguntaFrecuente[] {
    const busqueda = terminos(consulta);

    return busqueda.length === 0
        ? []
        : preguntas.filter((p) =>
              busqueda.every((b) =>
                  contiene(`${p.pregunta} ${p.respuesta}`, b),
              ),
          );
}
