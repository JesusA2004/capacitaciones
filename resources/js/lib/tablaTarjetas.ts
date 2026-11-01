import type { Directive } from 'vue';

function etiquetar(contenedor: HTMLElement): void {
    const tabla =
        contenedor instanceof HTMLTableElement
            ? contenedor
            : contenedor.querySelector('table');

    if (!tabla) {
        return;
    }

    tabla.classList.add('tabla-tarjetas');

    const encabezados = tabla.tHead?.rows[tabla.tHead.rows.length - 1];

    if (!encabezados) {
        return;
    }

    // Una columna con colspan ocupa varias posiciones.
    const etiquetas: string[] = [];

    for (const celda of Array.from(encabezados.cells)) {
        for (let i = 0; i < celda.colSpan; i++) {
            etiquetas.push(celda.textContent?.trim() ?? '');
        }
    }

    for (const cuerpo of Array.from(tabla.tBodies)) {
        for (const fila of Array.from(cuerpo.rows)) {
            let posicion = 0;

            for (const celda of Array.from(fila.cells)) {
                celda.setAttribute('data-etiqueta', etiquetas[posicion] ?? '');
                posicion += celda.colSpan;
            }
        }
    }
}

/**
 * En móvil cada fila se vuelve una tarjeta (`.tabla-tarjetas`, app.css): una sola
 * plantilla, sin duplicar la tabla ni hacer scroll horizontal. La etiqueta de cada
 * dato sale del encabezado de su columna.
 */
export const vTablaTarjetas: Directive<HTMLElement> = {
    mounted: etiquetar,
    updated: etiquetar,
};
