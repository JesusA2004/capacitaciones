/**
 * Utilidades de Tailwind que ACOMODAN un control dentro de su contenedor
 * (ancho, flex/grid, márgenes, visibilidad) frente a las que lo PINTAN
 * (alto, texto, fondo, borde…). Los controles con una envoltura (select
 * nativo con su flecha, combobox con buscador) reciben un solo `class` del
 * padre: lo de acomodo va a la envoltura y el control interno la llena; lo
 * visual va al control. Así `class="w-full"` o `class="w-1/2 lg:hidden"`
 * funcionan igual que en un `<select>` normal y un ancho en % no se aplica
 * dos veces.
 */
const ACOMODO =
    /^-?(w-|min-w-|max-w-|flex|grow|shrink|basis-|col-|row-|self-|justify-self-|place-self-|order-|m[trblxyse]?-|hidden$|block$|inline-block$|inline-flex$|contents$)/;

export function separarClasesAcomodo(clases: string | null | undefined): {
    acomodo: string;
    resto: string;
} {
    const acomodo: string[] = [];
    const resto: string[] = [];

    for (const clase of (clases ?? '').split(/\s+/).filter(Boolean)) {
        // Sin variantes (sm:, hover:…) ni «!» para decidir a dónde va.
        const base = clase.slice(clase.lastIndexOf(':') + 1).replace(/^!/, '');

        (ACOMODO.test(base) ? acomodo : resto).push(clase);
    }

    return { acomodo: acomodo.join(' '), resto: resto.join(' ') };
}
