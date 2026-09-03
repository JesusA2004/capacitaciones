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

    /**
     * Limpia todos los filtros a cadena vacía — nunca "vuelve" a
     * `valoresIniciales`, porque esos son los valores que traía la URL al
     * montar el componente (que pueden no estar vacíos: por ejemplo tras
     * recargar la página con un filtro ya activo, o al entrar desde un
     * enlace con query string precargada). Revertir a ese estado hacía que
     * el botón "Limpiar filtros" no hiciera nada visible en esos casos.
     */
    function limpiar() {
        for (const clave of Object.keys(filtros) as (keyof T)[]) {
            filtros[clave] = '' as T[typeof clave];
        }

        aplicar();
    }

    return { filtros, aplicar, aplicarConDebounce, limpiar };
}
