import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { reactive } from 'vue';

type OpcionesFiltros = {
    debounceMs?: number;
};

/**
 * Estado de filtros de una tabla administrativa, sincronizado con el
 * servidor via Inertia (router.get), preservando scroll/estado de la pagina.
 */
export function useFiltros<T extends Record<string, FormDataConvertible>>(
    url: string,
    valoresIniciales: T,
    opciones: OpcionesFiltros = {},
) {
    const filtros = reactive({ ...valoresIniciales }) as T;
    let temporizador: ReturnType<typeof setTimeout> | undefined;

    function aplicar() {
        router.get(url, filtros, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    }

    function aplicarConDebounce(ms = opciones.debounceMs ?? 400) {
        clearTimeout(temporizador);
        temporizador = setTimeout(aplicar, ms);
    }

    function limpiar() {
        Object.assign(filtros, valoresIniciales);
        aplicar();
    }

    return { filtros, aplicar, aplicarConDebounce, limpiar };
}
