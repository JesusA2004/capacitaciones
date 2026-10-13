<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, Search, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { dashboard } from '@/routes';
import { show } from '@/routes/rh/perfiles-puesto';
import type { PuestoConPerfil } from '@/types';

const props = defineProps<{
    puestos: PuestoConPerfil[];
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Descriptivos y perfiles', href: '' },
        ],
    },
});

type Filtro = 'todos' | 'listos' | 'pendientes';
const filtro = ref<Filtro>('todos');
const busqueda = ref('');

function plegar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const conteo = computed(() => ({
    todos: props.puestos.length,
    listos: props.puestos.filter((p) => p.avance > 0).length,
    pendientes: props.puestos.filter((p) => p.avance === 0).length,
}));

const FILTROS: { valor: Filtro; etiqueta: string }[] = [
    { valor: 'todos', etiqueta: 'Todos' },
    { valor: 'listos', etiqueta: 'Con descriptivo' },
    { valor: 'pendientes', etiqueta: 'Pendientes' },
];

const grupos = computed(() => {
    const q = plegar(busqueda.value.trim());
    const mapa = new Map<string, PuestoConPerfil[]>();

    for (const p of props.puestos) {
        if (filtro.value === 'listos' && p.avance === 0) {
            continue;
        }

        if (filtro.value === 'pendientes' && p.avance > 0) {
            continue;
        }

        if (q && !plegar(`${p.nombre} ${p.departamento ?? ''}`).includes(q)) {
            continue;
        }

        const area = p.departamento ?? 'Sin área';
        mapa.set(area, [...(mapa.get(area) ?? []), p]);
    }

    return [...mapa.entries()].map(([area, puestos]) => ({ area, puestos }));
});

function iniciales(nombre: string): string {
    return nombre
        .split(/\s+/)
        .filter((p) => p.length > 2)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase();
}

// Anillo de avance (SVG): circunferencia de r=16.
const CIRC = 2 * Math.PI * 16;
</script>

<template>
    <Head title="Descriptivos y perfiles de puesto" />

    <div class="pagina-ancha flex flex-col gap-6">
        <header
            class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
        >
            <div>
                <h1 class="font-serif text-3xl text-[var(--mrl-petroleo)]">
                    Descriptivos y perfiles de puesto
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    El deber ser de cada puesto y a quién buscamos para él.
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3 sm:flex">
                <div
                    class="rounded-2xl bg-[var(--mrl-petroleo)] px-4 py-3 text-white"
                >
                    <p class="text-2xl font-semibold">{{ conteo.listos }}</p>
                    <p class="text-xs text-white/70">con descriptivo</p>
                </div>
                <div class="rounded-2xl bg-[var(--mrl-crema)] px-4 py-3">
                    <p
                        class="text-2xl font-semibold text-[var(--mrl-dorado-oscuro)]"
                    >
                        {{ conteo.pendientes }}
                    </p>
                    <p class="text-xs text-muted-foreground">pendientes</p>
                </div>
            </div>
        </header>

        <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
            <div class="relative flex-1">
                <Search
                    class="absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar puesto o área"
                    class="min-h-11 w-full rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] pr-3 pl-10 text-sm outline-none focus:border-[var(--mrl-petroleo)]/50 focus:ring-2 focus:ring-[var(--mrl-petroleo)]/15"
                />
            </div>
            <div
                class="inline-flex max-w-full overflow-x-auto rounded-2xl bg-[var(--mrl-fondo)] p-1"
                role="tablist"
            >
                <button
                    v-for="f in FILTROS"
                    :key="f.valor"
                    type="button"
                    role="tab"
                    :aria-selected="filtro === f.valor"
                    class="inline-flex min-h-9 shrink-0 items-center gap-1.5 rounded-xl px-3.5 text-sm font-medium transition-colors"
                    :class="
                        filtro === f.valor
                            ? 'bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-sm'
                            : 'text-muted-foreground hover:text-[var(--mrl-texto)]'
                    "
                    @click="filtro = f.valor"
                >
                    {{ f.etiqueta }}
                    <span class="text-xs text-muted-foreground">{{
                        conteo[f.valor]
                    }}</span>
                </button>
            </div>
        </div>

        <section v-for="g in grupos" :key="g.area" class="flex flex-col gap-3">
            <h2
                class="text-xs font-semibold tracking-[0.14em] text-[var(--mrl-dorado-oscuro)] uppercase"
            >
                {{ g.area }}
            </h2>
            <div
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
            >
                <Link
                    v-for="p in g.puestos"
                    :key="p.id"
                    :href="show.url(p.id)"
                    class="group flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3.5"
                >
                    <div class="relative size-11 shrink-0">
                        <svg viewBox="0 0 40 40" class="size-11 -rotate-90">
                            <circle
                                cx="20"
                                cy="20"
                                r="16"
                                fill="none"
                                stroke="var(--mrl-fondo)"
                                stroke-width="4"
                            />
                            <circle
                                cx="20"
                                cy="20"
                                r="16"
                                fill="none"
                                stroke="var(--mrl-petroleo)"
                                stroke-width="4"
                                stroke-linecap="round"
                                :stroke-dasharray="CIRC"
                                :stroke-dashoffset="CIRC * (1 - p.avance / 100)"
                            />
                        </svg>
                        <span
                            class="absolute inset-0 flex items-center justify-center text-[11px] font-semibold text-[var(--mrl-petroleo)]"
                            >{{ iniciales(p.nombre) }}</span
                        >
                    </div>
                    <div class="min-w-0 flex-1">
                        <p
                            class="leading-tight font-semibold text-[var(--mrl-texto)]"
                        >
                            {{ p.nombre }}
                        </p>
                        <p
                            class="mt-0.5 flex items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1">
                                <Users class="size-3" /> {{ p.ocupantes }}
                            </span>
                            <span
                                v-if="p.avance === 0"
                                class="rounded-full bg-[var(--mrl-crema)] px-2 py-px text-[var(--mrl-dorado-oscuro)]"
                                >Pendiente</span
                            >
                            <span v-else>{{ p.avance }} %</span>
                        </p>
                    </div>
                    <ChevronRight
                        class="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5"
                    />
                </Link>
            </div>
        </section>

        <p
            v-if="grupos.length === 0"
            class="py-12 text-center text-sm text-muted-foreground"
        >
            No hay puestos con ese filtro.
        </p>
    </div>
</template>
