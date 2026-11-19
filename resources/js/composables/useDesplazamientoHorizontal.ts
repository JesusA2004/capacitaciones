import { onBeforeUnmount, ref, watch } from 'vue';

/**
 * Riel horizontal con flechas: sabe si hay contenido escondido a la
 * izquierda/derecha y desplaza un «paso» (el primer hijo marcado con
 * `data-paso`, o el 80 % del ancho visible). Sirve para cualquier fila que
 * desborde (fases del embudo, columnas de un tablero) sin depender de la
 * barra de scroll, que en touch y en Windows no siempre se ve.
 *
 * El elemento puede montarse más tarde (v-if): el observador se engancha
 * cuando la ref recibe el nodo.
 */
export function useDesplazamientoHorizontal() {
    const riel = ref<HTMLElement | null>(null);
    const alInicio = ref(true);
    const alFinal = ref(true);

    function medir(): void {
        const el = riel.value;

        if (!el) {
            return;
        }

        alInicio.value = el.scrollLeft <= 2;
        alFinal.value = el.scrollLeft + el.clientWidth >= el.scrollWidth - 2;
    }

    function mover(direccion: 1 | -1): void {
        const el = riel.value;

        if (!el) {
            return;
        }

        const paso =
            el.querySelector<HTMLElement>('[data-paso]')?.offsetWidth ??
            el.clientWidth * 0.8;

        el.scrollBy({ left: direccion * paso, behavior: 'smooth' });
    }

    /**
     * Centra en el riel el elemento que coincide con el selector, moviendo
     * SOLO el scroll horizontal (scrollIntoView también movía la página).
     */
    function centrar(selector: string, suave = true): void {
        const el = riel.value;
        const objetivo = el?.querySelector<HTMLElement>(selector);

        if (!el || !objetivo || el.scrollWidth <= el.clientWidth) {
            return;
        }

        const caja = el.getBoundingClientRect();
        const destino = objetivo.getBoundingClientRect();
        const izquierda =
            el.scrollLeft +
            (destino.left - caja.left) -
            (caja.width - destino.width) / 2;

        el.scrollTo({ left: izquierda, behavior: suave ? 'smooth' : 'auto' });
    }

    let observador: ResizeObserver | null = null;

    watch(riel, (nuevo, anterior) => {
        anterior?.removeEventListener('scroll', medir);
        observador?.disconnect();

        if (!nuevo) {
            return;
        }

        nuevo.addEventListener('scroll', medir, { passive: true });

        if (typeof ResizeObserver !== 'undefined') {
            observador = new ResizeObserver(medir);
            observador.observe(nuevo);
        }

        medir();
    });

    onBeforeUnmount(() => {
        riel.value?.removeEventListener('scroll', medir);
        observador?.disconnect();
    });

    return { riel, alInicio, alFinal, mover, medir, centrar };
}
