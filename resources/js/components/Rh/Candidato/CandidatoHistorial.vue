<script setup lang="ts">
import {
    ChevronDown,
    Flag,
    History,
    LayoutList,
    ListTree,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import SeccionFicha from '@/components/Rh/Candidato/SeccionFicha.vue';
import { Button } from '@/components/ui/button';
import {
    CATEGORIAS_HISTORIAL,
    agruparHistorial,
    horaDe,
    mesDe,
} from '@/lib/lineaTiempo';
import type { CategoriaHistorial, GrupoHistorial } from '@/lib/lineaTiempo';
import { TONO_SUAVE } from '@/lib/tonos';
import type { ItemTimeline } from '@/types';

/**
 * Línea de tiempo del candidato en dos tamaños:
 *  - `resumen`: solo los últimos hitos clave, en renglones compactos, con
 *    el botón «Ver todo el historial (N)».
 *  - `completo`: todo el historial con filtros por categoría y scroll
 *    propio; los eventos repetidos seguidos vienen juntos y se despliegan.
 */
const props = withDefaults(
    defineProps<{
        items: ItemTimeline[];
        modo?: 'resumen' | 'completo';
        /** Cuántos hitos enseña el resumen. */
        limite?: number;
        indice?: number;
    }>(),
    { modo: 'resumen', limite: 6, indice: 0 },
);

const emit = defineEmits<{ verTodo: [] }>();

const grupos = computed(() => agruparHistorial(props.items));

/** Últimos hitos; si el proceso aún no tiene ninguno, lo más reciente. */
const destacados = computed(() => {
    const hitos = grupos.value.filter((g) => g.esHito);

    return (hitos.length ? hitos : grupos.value).slice(0, props.limite);
});

const filtro = ref<CategoriaHistorial | 'todo' | 'hitos'>('todo');

const filtros = computed(() => {
    const conteo = new Map<CategoriaHistorial, number>();

    for (const g of grupos.value) {
        conteo.set(
            g.categoria,
            (conteo.get(g.categoria) ?? 0) + g.eventos.length,
        );
    }

    return [
        {
            clave: 'todo' as const,
            etiqueta: 'Todo',
            icono: LayoutList,
            total: props.items.length,
        },
        {
            clave: 'hitos' as const,
            etiqueta: 'Hitos clave',
            icono: Flag,
            total: grupos.value.filter((g) => g.esHito).length,
        },
        ...(Object.keys(CATEGORIAS_HISTORIAL) as CategoriaHistorial[])
            .filter((c) => conteo.has(c))
            .map((c) => ({
                clave: c,
                etiqueta: CATEGORIAS_HISTORIAL[c].etiqueta,
                icono: CATEGORIAS_HISTORIAL[c].icono,
                total: conteo.get(c) ?? 0,
            })),
    ].filter((f) => f.total > 0);
});

const visibles = computed(() => {
    if (filtro.value === 'todo') {
        return grupos.value;
    }

    return grupos.value.filter((g) =>
        filtro.value === 'hitos' ? g.esHito : g.categoria === filtro.value,
    );
});

/** Lista completa con separador por mes. */
const porMes = computed(() => {
    const meses: { mes: string; grupos: GrupoHistorial[] }[] = [];

    for (const g of visibles.value) {
        const mes = mesDe(g.fecha);
        const ultimo = meses[meses.length - 1];

        if (ultimo?.mes === mes) {
            ultimo.grupos.push(g);
        } else {
            meses.push({ mes, grupos: [g] });
        }
    }

    return meses;
});

const abiertos = ref<Set<string>>(new Set());

function alternar(clave: string): void {
    const copia = new Set(abiertos.value);

    if (!copia.delete(clave)) {
        copia.add(clave);
    }

    abiertos.value = copia;
}

function tono(g: GrupoHistorial): string {
    return TONO_SUAVE[CATEGORIAS_HISTORIAL[g.categoria].tono];
}
</script>

<template>
    <!-- Resumen: últimos hitos clave. -->
    <SeccionFicha
        v-if="modo === 'resumen'"
        titulo="Últimos hitos"
        :icono="History"
        tono="info"
        :indice="indice"
        etiqueta-aria="Línea de tiempo (resumen)"
        data-linea-tiempo="resumen"
    >
        <p v-if="!destacados.length" class="text-sm text-muted-foreground">
            Sin eventos registrados.
        </p>
        <ol v-else class="flex flex-col gap-1">
            <li
                v-for="g in destacados"
                :key="g.clave"
                class="flex min-w-0 items-start gap-2.5 rounded-xl px-1.5 py-1.5 transicion-ui hover:bg-crema/40"
                :title="g.descripcion ?? undefined"
            >
                <span
                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg"
                    :class="tono(g)"
                    aria-hidden="true"
                >
                    <component :is="g.icono" class="size-3.5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm leading-snug font-medium break-words">
                        {{ g.titulo }}
                    </p>
                    <p class="text-xs break-words text-muted-foreground">
                        {{ g.cuando
                        }}<template v-if="g.actor"> · {{ g.actor }}</template>
                    </p>
                </div>
            </li>
        </ol>
        <Button
            v-if="items.length"
            variant="outline"
            size="sm"
            class="self-start"
            data-accion="ver-historial"
            @click="emit('verTodo')"
        >
            <ListTree class="size-4" />
            Ver todo el historial ({{ items.length }})
        </Button>
    </SeccionFicha>

    <!-- Completo: todo, con filtros y scroll propio. -->
    <SeccionFicha
        v-else
        titulo="Historial completo"
        :icono="History"
        tono="info"
        :contador="items.length"
        descripcion="Del más reciente al más antiguo. Los eventos repetidos vienen juntos."
        :indice="indice"
        etiqueta-aria="Línea de tiempo completa"
        data-linea-tiempo="completo"
    >
        <p v-if="!grupos.length" class="text-sm text-muted-foreground">
            Sin eventos registrados.
        </p>
        <template v-else>
            <div
                class="flex flex-wrap gap-1.5"
                role="group"
                aria-label="Filtrar historial por categoría"
            >
                <button
                    v-for="f in filtros"
                    :key="f.clave"
                    type="button"
                    :aria-pressed="filtro === f.clave"
                    class="inline-flex shrink-0 items-center gap-1.5 rounded-xl border px-2.5 py-1.5 text-xs font-medium transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        filtro === f.clave
                            ? 'border-primary/30 bg-salvia text-[var(--mrl-petroleo)] shadow-xs'
                            : 'border-transparent bg-[var(--mrl-fondo)] text-muted-foreground hover:bg-crema/60 hover:text-foreground'
                    "
                    @click="filtro = f.clave"
                >
                    <component
                        :is="f.icono"
                        class="size-3.5"
                        aria-hidden="true"
                    />
                    {{ f.etiqueta }}
                    <span
                        class="rounded-full bg-[var(--mrl-superficie)] px-1.5 tabular-nums"
                        >{{ f.total }}</span
                    >
                </button>
            </div>

            <div
                class="-mr-2 max-h-[70vh] overflow-y-auto overscroll-contain pr-2"
                tabindex="0"
                role="region"
                aria-label="Eventos del historial"
            >
                <section
                    v-for="bloque in porMes"
                    :key="bloque.mes"
                    class="mb-3 last:mb-0"
                >
                    <h3
                        class="sticky top-0 z-10 mb-1 bg-[var(--mrl-superficie)] py-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                    >
                        {{ bloque.mes }}
                    </h3>
                    <ol class="flex flex-col">
                        <li
                            v-for="g in bloque.grupos"
                            :key="g.clave"
                            class="border-b border-[var(--mrl-borde)]/60 last:border-b-0"
                        >
                            <div
                                class="grid min-w-0 grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-0.5 rounded-xl px-1.5 py-2 transicion-ui hover:bg-crema/40 md:grid-cols-[auto_minmax(0,1fr)_auto]"
                            >
                                <span
                                    class="row-span-2 mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-lg md:row-span-1"
                                    :class="tono(g)"
                                    aria-hidden="true"
                                >
                                    <component :is="g.icono" class="size-4" />
                                </span>
                                <div class="min-w-0">
                                    <p
                                        class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-sm leading-snug font-medium break-words"
                                    >
                                        {{ g.titulo }}
                                        <button
                                            v-if="g.eventos.length > 1"
                                            type="button"
                                            class="inline-flex items-center gap-0.5 rounded-md text-xs font-semibold text-[var(--mrl-petroleo)] hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :aria-expanded="
                                                abiertos.has(g.clave)
                                            "
                                            @click="alternar(g.clave)"
                                        >
                                            {{
                                                abiertos.has(g.clave)
                                                    ? 'Ocultar'
                                                    : 'Ver cada uno'
                                            }}
                                            <ChevronDown
                                                class="size-3.5 transicion-ui"
                                                :class="
                                                    abiertos.has(g.clave)
                                                        ? 'rotate-180'
                                                        : ''
                                                "
                                                aria-hidden="true"
                                            />
                                        </button>
                                    </p>
                                    <p
                                        v-if="g.descripcion"
                                        class="text-sm leading-snug break-words text-muted-foreground"
                                    >
                                        {{ g.descripcion }}
                                    </p>
                                </div>
                                <p
                                    class="col-start-2 flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground md:col-start-3 md:max-w-64 md:flex-col md:items-end md:text-right"
                                >
                                    <span class="tabular-nums">{{
                                        g.cuando
                                    }}</span>
                                    <span
                                        v-if="g.actor"
                                        class="inline-flex items-center gap-1 break-words"
                                    >
                                        <UserRound
                                            class="size-3 shrink-0"
                                            aria-hidden="true"
                                        />
                                        {{ g.actor }}
                                    </span>
                                </p>
                            </div>
                            <ol
                                v-if="
                                    g.eventos.length > 1 &&
                                    abiertos.has(g.clave)
                                "
                                class="mb-2 ml-12 flex entrada-suave flex-col gap-1 border-l-2 border-[var(--mrl-borde)] pl-3 text-xs text-muted-foreground"
                            >
                                <li
                                    v-for="(ev, i) in g.eventos"
                                    :key="i"
                                    class="break-words"
                                >
                                    <span class="tabular-nums">{{
                                        horaDe(ev.fecha)
                                    }}</span>
                                    · {{ ev.titulo
                                    }}<template v-if="ev.descripcion">
                                        — {{ ev.descripcion }}</template
                                    >
                                </li>
                            </ol>
                        </li>
                    </ol>
                </section>
                <p
                    v-if="!visibles.length"
                    class="py-4 text-sm text-muted-foreground"
                >
                    No hay eventos en esta categoría.
                </p>
            </div>
        </template>
    </SeccionFicha>
</template>
