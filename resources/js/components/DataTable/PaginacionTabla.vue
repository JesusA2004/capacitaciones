<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Info } from '@lucide/vue';
import { computed, onMounted } from 'vue';
import SelectorPorPagina from '@/components/DataTable/SelectorPorPagina.vue';
import type { PorPagina } from '@/composables/usePaginacion';
import {
    guardarPreferencia,
    leerPreferencia,
    marcarPreferenciaAplicada,
    OPCIONES_POR_PAGINA,
    PARAMETRO_LEGADO,
    PARAMETRO_POR_PAGINA,
    porPaginaDeLaUrl,
    VALOR_TODOS,
} from '@/composables/usePaginacion';

/**
 * Pie de tabla de TODOS los listados: cuántos se muestran, «Mostrar [20]
 * por página» (10 · 20 · 30 · 40 · 50 · Todos) y navegación.
 *
 * - Modo servidor (por defecto): el tamaño viaja en la URL como
 *   `por_pagina` junto con los filtros que ya estén en ella, y lo respeta
 *   el backend (App\Support\Paginacion). «Todos» tiene un tope de
 *   seguridad; si recorta, aquí se avisa.
 * - Modo `local`: para tablas que ya traen todas sus filas (ver
 *   usePaginacionLocal); solo emite eventos, no visita el servidor.
 *
 * La elección se recuerda por lista en este navegador.
 */
const props = withDefaults(
    defineProps<{
        datos: {
            current_page: number;
            last_page: number;
            per_page: number;
            from?: number | null;
            to?: number | null;
            total: number;
            links?: { url: string | null; label: string; active: boolean }[];
        };
        /** Paginación en el navegador (emite eventos en vez de navegar). */
        local?: boolean;
        /** Selección actual en modo local. */
        porPagina?: PorPagina;
        /** Clave para recordar la elección (por defecto, la ruta). */
        clave?: string;
    }>(),
    { local: false, porPagina: undefined, clave: undefined },
);

const emit = defineEmits<{
    'cambiar-pagina': [pagina: number];
    'cambiar-por-pagina': [valor: PorPagina];
}>();

/** Tamaño que pedía el antiguo botón «Todos» (`per_page=1000`). */
const TODOS_LEGADO = 1000;

const claveLista = computed(
    () =>
        props.clave ??
        (typeof window !== 'undefined' ? window.location.pathname : 'lista'),
);

const seleccionado = computed<PorPagina>(() => {
    if (props.local) {
        return props.porPagina ?? props.datos.per_page;
    }

    const deUrl = porPaginaDeLaUrl();

    if (deUrl !== null) {
        return deUrl;
    }

    if (
        typeof window !== 'undefined' &&
        Number(
            new URLSearchParams(window.location.search).get(PARAMETRO_LEGADO),
        ) >= TODOS_LEGADO
    ) {
        return VALOR_TODOS;
    }

    return props.datos.per_page;
});

/** «Todos» llegó al tope del servidor: hay más filas de las que se ven. */
const recortado = computed(
    () =>
        !props.local &&
        seleccionado.value === VALOR_TODOS &&
        props.datos.total > props.datos.per_page,
);

const visible = computed(
    () =>
        props.datos.total > OPCIONES_POR_PAGINA[0] || props.datos.last_page > 1,
);

// Solo números y elipsis; anterior/siguiente van en flechas.
const paginas = computed(() => {
    if (props.datos.links?.length) {
        return props.datos.links.slice(1, -1);
    }

    if (!props.local) {
        return [];
    }

    return numerosLocales(props.datos.current_page, props.datos.last_page);
});

/** Botones numerados para el modo local, con elipsis si son muchos. */
function numerosLocales(actual: number, ultima: number) {
    const numeros = new Set<number>([1, ultima]);

    for (let n = actual - 2; n <= actual + 2; n++) {
        if (n >= 1 && n <= ultima) {
            numeros.add(n);
        }
    }

    const ordenados = [...numeros].sort((a, b) => a - b);
    const enlaces: { url: string | null; label: string; active: boolean }[] =
        [];

    ordenados.forEach((n, i) => {
        if (i > 0 && n - ordenados[i - 1] > 1) {
            enlaces.push({ url: null, label: '...', active: false });
        }

        enlaces.push({ url: null, label: String(n), active: n === actual });
    });

    return enlaces;
}

function visitar(parametros: Record<string, string | number>) {
    const actuales = Object.fromEntries(
        new URLSearchParams(window.location.search),
    );

    router.get(
        window.location.pathname,
        { ...actuales, ...parametros },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function irAPagina(pagina: number) {
    if (
        pagina < 1 ||
        pagina > props.datos.last_page ||
        pagina === props.datos.current_page
    ) {
        return;
    }

    if (props.local) {
        emit('cambiar-pagina', pagina);

        return;
    }

    visitar({ page: pagina });
}

function visitarConTamano(valor: PorPagina) {
    const actuales = Object.fromEntries(
        new URLSearchParams(window.location.search),
    );
    delete actuales[PARAMETRO_LEGADO];

    router.get(
        window.location.pathname,
        { ...actuales, [PARAMETRO_POR_PAGINA]: String(valor), page: 1 },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function cambiarTamano(valor: PorPagina) {
    guardarPreferencia(claveLista.value, valor);

    if (props.local) {
        emit('cambiar-por-pagina', valor);

        return;
    }

    visitarConTamano(valor);
}

function numeroDe(enlace: {
    url: string | null;
    label: string;
}): number | null {
    const n = Number(enlace.label);

    return Number.isInteger(n) ? n : null;
}

/*
 * Si la URL no dice cuántos mostrar pero el usuario ya eligió antes un
 * tamaño para esta lista, se aplica (una sola vez por URL, aunque la
 * página tenga dos tablas).
 */
onMounted(() => {
    if (props.local) {
        return;
    }

    const query = new URLSearchParams(window.location.search);

    if (query.has(PARAMETRO_POR_PAGINA) || query.has(PARAMETRO_LEGADO)) {
        return;
    }

    const preferencia = leerPreferencia(claveLista.value);

    if (
        preferencia === null ||
        preferencia === props.datos.per_page ||
        (preferencia === VALOR_TODOS && props.datos.last_page <= 1) ||
        !marcarPreferenciaAplicada(window.location.href)
    ) {
        return;
    }

    visitarConTamano(preferencia);
});
</script>

<template>
    <div v-if="visible" class="space-y-2">
        <p
            v-if="recortado"
            class="flex items-start gap-2 rounded-xl bg-crema px-3 py-2 text-sm text-[var(--mrl-texto)]"
            role="status"
        >
            <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <span>
                Se muestran las primeras {{ datos.per_page }} de
                {{ datos.total }} filas («Todos» tiene un tope para no saturar
                el navegador). Usa los filtros para acotar la lista.
            </span>
        </p>

        <nav
            class="flex flex-col gap-3 text-sm sm:flex-row sm:items-center sm:justify-between"
            aria-label="Paginación"
        >
            <div
                class="flex flex-wrap items-center gap-x-4 gap-y-2 text-muted-foreground"
            >
                <span>
                    {{ datos.from ?? 0 }}–{{ datos.to ?? 0 }} de
                    <strong class="font-semibold text-[var(--mrl-texto)]">{{
                        datos.total
                    }}</strong>
                </span>
                <SelectorPorPagina
                    :model-value="seleccionado"
                    @update:model-value="cambiarTamano"
                />
            </div>

            <div v-if="datos.last_page > 1" class="flex items-center gap-1">
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded-lg border border-[var(--mrl-borde)] hover:bg-[var(--mrl-fondo)] disabled:opacity-40"
                    :disabled="datos.current_page <= 1"
                    aria-label="Página anterior"
                    @click="irAPagina(datos.current_page - 1)"
                >
                    <ChevronLeft class="size-4" />
                </button>
                <template v-if="paginas.length">
                    <button
                        v-for="(enlace, i) in paginas"
                        :key="i"
                        type="button"
                        class="hidden min-w-9 rounded-lg px-2.5 py-1.5 text-sm transition-colors sm:inline-block"
                        :class="[
                            enlace.active
                                ? 'bg-[var(--mrl-petroleo)] text-white'
                                : 'hover:bg-[var(--mrl-fondo)]',
                            numeroDe(enlace) === null
                                ? 'pointer-events-none'
                                : '',
                        ]"
                        :disabled="numeroDe(enlace) === null"
                        :aria-current="enlace.active ? 'page' : undefined"
                        @click="
                            numeroDe(enlace) !== null &&
                            irAPagina(numeroDe(enlace) as number)
                        "
                    >
                        {{ enlace.label }}
                    </button>
                </template>
                <span
                    class="px-2 text-xs text-muted-foreground"
                    :class="paginas.length ? 'sm:hidden' : ''"
                >
                    {{ datos.current_page }} / {{ datos.last_page }}
                </span>
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded-lg border border-[var(--mrl-borde)] hover:bg-[var(--mrl-fondo)] disabled:opacity-40"
                    :disabled="datos.current_page >= datos.last_page"
                    aria-label="Página siguiente"
                    @click="irAPagina(datos.current_page + 1)"
                >
                    <ChevronRight class="size-4" />
                </button>
            </div>
        </nav>
    </div>
</template>
