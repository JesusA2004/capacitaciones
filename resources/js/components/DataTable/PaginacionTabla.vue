<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed } from 'vue';

/**
 * Pie de tabla: cuántos se muestran, cuántas filas por página (20 · 30 ·
 * 50 · Todos) y navegación. El tamaño viaja como `per_page` en la URL y lo
 * respeta el backend (App\Support\Paginacion), junto con los filtros que ya
 * estén en la URL.
 */
const props = defineProps<{
    datos: {
        current_page: number;
        last_page: number;
        per_page: number;
        from?: number | null;
        to?: number | null;
        total: number;
        links?: { url: string | null; label: string; active: boolean }[];
    };
}>();

/** «Todos» = el máximo que acepta el backend. */
const TODOS = 1000;
const OPCIONES = [
    { valor: 20, etiqueta: '20' },
    { valor: 30, etiqueta: '30' },
    { valor: 50, etiqueta: '50' },
    { valor: TODOS, etiqueta: 'Todos' },
];

const visible = computed(
    () => props.datos.total > OPCIONES[0].valor || props.datos.last_page > 1,
);

// Solo números y elipsis; anterior/siguiente van en flechas.
const paginas = computed(() => (props.datos.links ?? []).slice(1, -1));

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
        pagina >= 1 &&
        pagina <= props.datos.last_page &&
        pagina !== props.datos.current_page
    ) {
        visitar({ page: pagina });
    }
}

function cambiarTamano(valor: number) {
    visitar({ per_page: valor, page: 1 });
}

function numeroDe(enlace: {
    url: string | null;
    label: string;
}): number | null {
    const n = Number(enlace.label);

    return Number.isInteger(n) ? n : null;
}
</script>

<template>
    <nav
        v-if="visible"
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
            <div
                class="inline-flex items-center gap-1 rounded-xl bg-[var(--mrl-fondo)] p-0.5"
                role="group"
                aria-label="Filas por página"
            >
                <button
                    v-for="o in OPCIONES"
                    :key="o.valor"
                    type="button"
                    class="min-h-8 rounded-lg px-2.5 text-xs font-medium transition-colors"
                    :class="
                        datos.per_page === o.valor ||
                        (o.valor === TODOS && datos.per_page >= TODOS)
                            ? 'bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-sm'
                            : 'hover:text-[var(--mrl-texto)]'
                    "
                    :aria-pressed="datos.per_page === o.valor"
                    @click="cambiarTamano(o.valor)"
                >
                    {{ o.etiqueta }}
                </button>
            </div>
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
                        numeroDe(enlace) === null ? 'pointer-events-none' : '',
                    ]"
                    :disabled="numeroDe(enlace) === null"
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
</template>
