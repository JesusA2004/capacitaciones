import type { MaybeRefOrGetter } from 'vue';
import { computed, ref, toValue, watch } from 'vue';

/**
 * Filas por página de cualquier listado (10 · 20 · 30 · 40 · 50 · Todos).
 *
 * - En listados del servidor el tamaño viaja en la URL como `por_pagina`
 *   (lo interpreta App\Support\Paginacion) y sobrevive a filtros y enlaces.
 * - La última elección de cada lista se recuerda en este navegador
 *   (localStorage, solo como comodidad: si falla, no pasa nada).
 * - `usePaginacionLocal` pagina en el navegador las tablas que ya traen
 *   todas sus filas, con el mismo selector.
 */

export const OPCIONES_POR_PAGINA = [10, 20, 30, 40, 50] as const;

export const VALOR_TODOS = 'todos';

export const PARAMETRO_POR_PAGINA = 'por_pagina';

/** Parámetro anterior; se sigue leyendo pero ya no se escribe. */
export const PARAMETRO_LEGADO = 'per_page';

export type PorPagina = number | typeof VALOR_TODOS;

const PREFIJO_ALMACEN = 'mrl.porPagina:';

function claveAlmacen(clave: string): string {
    return `${PREFIJO_ALMACEN}${clave}`;
}

/** Convierte lo que venga (URL, localStorage) a una opción válida o null. */
export function normalizarPorPagina(valor: unknown): PorPagina | null {
    if (
        typeof valor === 'string' &&
        valor.trim().toLowerCase() === VALOR_TODOS
    ) {
        return VALOR_TODOS;
    }

    const numero = Number(valor);

    return (OPCIONES_POR_PAGINA as readonly number[]).includes(numero)
        ? numero
        : null;
}

export function leerPreferencia(clave: string): PorPagina | null {
    try {
        return normalizarPorPagina(
            window.localStorage.getItem(claveAlmacen(clave)),
        );
    } catch {
        return null;
    }
}

export function guardarPreferencia(clave: string, valor: PorPagina): void {
    try {
        window.localStorage.setItem(claveAlmacen(clave), String(valor));
    } catch {
        // Modo privado o almacenamiento bloqueado: solo se pierde la comodidad.
    }
}

const urlsConPreferenciaAplicada = new Set<string>();

/**
 * Registra que ya se aplicó la preferencia guardada a esta URL. Devuelve
 * false si ya se había aplicado (una página con dos tablas no la aplica dos
 * veces, y nunca se entra en un ciclo de visitas).
 */
export function marcarPreferenciaAplicada(url: string): boolean {
    if (urlsConPreferenciaAplicada.has(url)) {
        return false;
    }

    urlsConPreferenciaAplicada.add(url);

    return true;
}

/** `por_pagina` que trae la URL actual (o null si no trae o no es válido). */
export function porPaginaDeLaUrl(): PorPagina | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return normalizarPorPagina(
        new URLSearchParams(window.location.search).get(PARAMETRO_POR_PAGINA),
    );
}

/**
 * Parámetros de tamaño de página a conservar al aplicar filtros: el
 * tamaño elegido se mantiene y la página vuelve a la 1 (el filtro cambia
 * el total).
 */
export function parametrosPorPaginaActuales(): Record<string, string> {
    const actual = porPaginaDeLaUrl();

    return actual === null ? {} : { [PARAMETRO_POR_PAGINA]: String(actual) };
}

type OpcionesPaginacionLocal = {
    /** Clave para recordar la elección (por defecto, la ruta de la página). */
    clave?: string;
    porDefecto?: PorPagina;
};

/**
 * Paginación en el navegador para tablas que ya traen todas sus filas.
 * Devuelve las filas de la página actual y un objeto `datos` con la misma
 * forma que un paginador de Laravel, para pasarlo a <PaginacionTabla local>.
 * Al cambiar las filas (por ejemplo, al filtrar) se vuelve a la página 1.
 */
export function usePaginacionLocal<T>(
    filas: MaybeRefOrGetter<T[]>,
    opciones: OpcionesPaginacionLocal = {},
) {
    const clave =
        opciones.clave ??
        (typeof window !== 'undefined' ? window.location.pathname : 'lista');
    const porPagina = ref<PorPagina>(
        leerPreferencia(clave) ?? opciones.porDefecto ?? 20,
    );
    const pagina = ref(1);

    const total = computed(() => toValue(filas).length);
    const tamano = computed(() =>
        porPagina.value === VALOR_TODOS
            ? Math.max(total.value, 1)
            : porPagina.value,
    );
    const ultimaPagina = computed(() =>
        Math.max(1, Math.ceil(total.value / tamano.value)),
    );

    watch(
        () => toValue(filas),
        () => {
            pagina.value = 1;
        },
    );

    watch(ultimaPagina, (ultima) => {
        if (pagina.value > ultima) {
            pagina.value = ultima;
        }
    });

    const filasPagina = computed(() => {
        const inicio = (pagina.value - 1) * tamano.value;

        return toValue(filas).slice(inicio, inicio + tamano.value);
    });

    const datos = computed(() => ({
        current_page: pagina.value,
        last_page: ultimaPagina.value,
        per_page: tamano.value,
        total: total.value,
        from: total.value === 0 ? null : (pagina.value - 1) * tamano.value + 1,
        to:
            total.value === 0
                ? null
                : Math.min(pagina.value * tamano.value, total.value),
    }));

    function irAPagina(nueva: number) {
        pagina.value = Math.min(Math.max(1, nueva), ultimaPagina.value);
    }

    function cambiarPorPagina(valor: PorPagina) {
        porPagina.value = valor;
        pagina.value = 1;
        guardarPreferencia(clave, valor);
    }

    return {
        datos,
        filasPagina,
        porPagina,
        pagina,
        clave,
        irAPagina,
        cambiarPorPagina,
    };
}
