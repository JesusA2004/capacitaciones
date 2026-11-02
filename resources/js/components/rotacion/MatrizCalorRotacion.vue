<script setup lang="ts">
import { ArrowDownWideNarrow, Grid3x3, Info } from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import { numero, pct, plural, useNavegacionRotacion } from '@/lib/rotacion';
import type {
    ColumnaMatrizRotacion,
    FilaMatrizRotacion,
    MatrizRotacion,
} from '@/types';

/**
 * Mapa de calor sucursal × causal homologada (solo bajas individuales). La
 * intensidad es relativa al máximo de la matriz; el número siempre se lee.
 * Tocar una celda abre el histórico de esa sucursal con esa causal. En
 * móvil se desplaza dentro de su propio contenedor.
 */
const props = defineProps<{ matriz: MatrizRotacion }>();

const nav = useNavegacionRotacion();

type Orden = { por: 'total' | 'nombre' | 'columna'; clave?: string };
const orden = ref<Orden>({ por: 'total' });

const filas = computed(() => {
    const lista = [...props.matriz.filas];
    const o = orden.value;

    if (o.por === 'nombre') {
        return lista.sort((a, b) => a.sucursal.localeCompare(b.sucursal, 'es'));
    }

    if (o.por === 'columna' && o.clave) {
        const k = o.clave;

        return lista.sort(
            (a, b) =>
                (b.celdas[k] ?? 0) - (a.celdas[k] ?? 0) || b.total - a.total,
        );
    }

    return lista.sort((a, b) => b.total - a.total);
});

function ordenarPor(o: Orden): void {
    orden.value = o;
}

const activa = ref<{
    fila: FilaMatrizRotacion;
    col: ColumnaMatrizRotacion;
} | null>(null);

function intensidad(n: number): number {
    return props.matriz.maximo > 0 ? n / props.matriz.maximo : 0;
}

function estiloCelda(n: number): Record<string, string> {
    if (n === 0) {
        return {};
    }

    const p = Math.round(10 + intensidad(n) * 70);

    return {
        backgroundColor: `color-mix(in oklab, var(--chart-1) ${p}%, transparent)`,
    };
}

function parteDe(n: number, total: number): number | null {
    return total > 0 ? Math.round((n * 1000) / total) / 10 : null;
}

function abrir(fila: FilaMatrizRotacion, col: ColumnaMatrizRotacion): void {
    nav.verHistorico({
        d_sucursal: fila.sucursal_id,
        d_causal: col.causal_analitica_id ?? 'pendiente',
    });
}

const textoActiva = computed(() => {
    const a = activa.value;

    if (!a) {
        return null;
    }

    const n = a.fila.celdas[a.col.clave] ?? 0;

    return `${a.fila.sucursal} · ${a.col.nombre}: ${plural(n, 'baja', 'bajas')} · ${pct(parteDe(n, a.fila.total))} de la sucursal · ${pct(parteDe(n, a.col.total))} de la causal`;
});
</script>

<template>
    <section
        class="flex min-w-0 flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
        aria-labelledby="titulo-matriz"
    >
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <h2 id="titulo-matriz" class="font-semibold">
                    Sucursal × causal
                </h2>
                <p class="text-xs text-muted-foreground">
                    Más intenso = más bajas. Toca una celda para ver a las
                    personas; toca un encabezado para ordenar.
                </p>
            </div>
            <span class="text-xs text-muted-foreground tabular-nums">{{
                plural(matriz.total, 'baja individual', 'bajas individuales')
            }}</span>
        </div>

        <EmptyState
            v-if="!matriz.filas.length"
            :icono="Grid3x3"
            titulo="Sin bajas individuales"
            descripcion="El mapa solo se arma con bajas que tienen persona y causal."
        />

        <template v-else>
            <p
                class="flex min-h-9 items-center gap-2 rounded-xl bg-crema/40 px-3 py-1.5 text-xs"
                aria-live="polite"
            >
                <Info
                    class="size-3.5 shrink-0 text-bronce"
                    aria-hidden="true"
                />
                {{
                    textoActiva ??
                    'Pasa el cursor o enfoca una celda para ver su detalle.'
                }}
            </p>

            <div class="tabla-scroll rounded-2xl border border-border/60">
                <table class="w-max min-w-full border-collapse text-xs">
                    <thead>
                        <tr class="bg-crema/50">
                            <th
                                scope="col"
                                class="sticky left-0 z-10 bg-crema px-3 py-2 text-left font-medium"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-primary"
                                    :aria-pressed="orden.por === 'nombre'"
                                    @click="ordenarPor({ por: 'nombre' })"
                                >
                                    Sucursal
                                </button>
                            </th>
                            <th
                                v-for="c in matriz.columnas"
                                :key="c.clave"
                                scope="col"
                                class="max-w-28 px-1.5 py-2 align-bottom font-medium"
                            >
                                <button
                                    type="button"
                                    class="inline-flex w-full items-end justify-center gap-0.5 text-center leading-tight hover:text-primary"
                                    :class="
                                        orden.por === 'columna' &&
                                        orden.clave === c.clave &&
                                        'text-primary'
                                    "
                                    :aria-pressed="
                                        orden.por === 'columna' &&
                                        orden.clave === c.clave
                                    "
                                    :aria-label="`Ordenar por ${c.nombre}`"
                                    @click="
                                        ordenarPor({
                                            por: 'columna',
                                            clave: c.clave,
                                        })
                                    "
                                >
                                    <span class="line-clamp-3">{{
                                        c.nombre
                                    }}</span>
                                    <ArrowDownWideNarrow
                                        v-if="
                                            orden.por === 'columna' &&
                                            orden.clave === c.clave
                                        "
                                        class="size-3 shrink-0"
                                    />
                                </button>
                            </th>
                            <th scope="col" class="px-3 py-2 font-medium">
                                <button
                                    type="button"
                                    class="hover:text-primary"
                                    :class="
                                        orden.por === 'total' && 'text-primary'
                                    "
                                    :aria-pressed="orden.por === 'total'"
                                    @click="ordenarPor({ por: 'total' })"
                                >
                                    Total
                                </button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="f in filas"
                            :key="f.sucursal_id"
                            class="border-t border-border/50"
                        >
                            <th
                                scope="row"
                                class="sticky left-0 z-10 max-w-44 truncate bg-card px-3 py-1.5 text-left font-medium"
                                :title="f.sucursal"
                            >
                                {{ f.sucursal }}
                            </th>
                            <td
                                v-for="c in matriz.columnas"
                                :key="c.clave"
                                class="p-0.5"
                            >
                                <button
                                    v-if="(f.celdas[c.clave] ?? 0) > 0"
                                    type="button"
                                    class="celda grid h-9 w-full min-w-11 place-items-center rounded-lg font-semibold tabular-nums"
                                    :class="
                                        intensidad(f.celdas[c.clave] ?? 0) >
                                        0.55
                                            ? 'text-primary-foreground'
                                            : 'text-foreground'
                                    "
                                    :style="estiloCelda(f.celdas[c.clave] ?? 0)"
                                    :aria-label="`${f.sucursal}, ${c.nombre}: ${f.celdas[c.clave]} bajas. Ver colaboradores`"
                                    @mouseenter="activa = { fila: f, col: c }"
                                    @focus="activa = { fila: f, col: c }"
                                    @mouseleave="activa = null"
                                    @blur="activa = null"
                                    @click="abrir(f, c)"
                                >
                                    {{ f.celdas[c.clave] }}
                                </button>
                                <span
                                    v-else
                                    class="grid h-9 min-w-11 place-items-center text-muted-foreground/40"
                                    aria-hidden="true"
                                    >·</span
                                >
                            </td>
                            <td
                                class="px-3 py-1.5 text-center font-semibold tabular-nums"
                            >
                                {{ numero(f.total) }}
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-border bg-crema/30">
                            <th
                                scope="row"
                                class="sticky left-0 z-10 bg-crema px-3 py-2 text-left font-semibold"
                            >
                                Total
                            </th>
                            <td
                                v-for="c in matriz.columnas"
                                :key="c.clave"
                                class="px-1.5 py-2 text-center font-semibold tabular-nums"
                            >
                                {{ numero(c.total) }}
                            </td>
                            <td
                                class="px-3 py-2 text-center font-bold tabular-nums"
                            >
                                {{ numero(matriz.total) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p
                v-if="matriz.sin_desglose > 0"
                class="text-[11px] text-muted-foreground"
            >
                {{ plural(matriz.sin_desglose, 'baja', 'bajas') }} del Excel sin
                desglose no aparecen en el mapa (no tienen causal).
            </p>
        </template>
    </section>
</template>

<style scoped>
.celda {
    transition:
        transform 140ms ease,
        box-shadow 160ms ease;
}

.celda:hover,
.celda:focus-visible {
    transform: scale(1.06);
    box-shadow: 0 0 0 2px var(--pastel-oro);
    outline: none;
}

@media (prefers-reduced-motion: reduce) {
    .celda {
        transition: none;
    }

    .celda:hover,
    .celda:focus-visible {
        transform: none;
    }
}
</style>
