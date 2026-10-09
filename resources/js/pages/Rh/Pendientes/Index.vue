<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    ArrowRight,
    CalendarClock,
    CheckCircle2,
    Eye,
    Flame,
    Inbox,
} from '@lucide/vue';
import { computed, reactive, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/pendientes';
import type { TareaBandeja } from '@/types';

type Paginado<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
type Serie = { clave: string; etiqueta: string; total: number };

type Atajo = {
    clave: string;
    titulo: string;
    descripcion: string;
    conteo: number;
    url: string;
    tono: 'primario' | 'oro' | 'alerta' | 'neutro';
};

const TONO_ATAJO: Record<Atajo['tono'], string> = {
    primario: 'bg-salvia text-primary',
    oro: 'bg-warning-soft text-warning',
    alerta: 'bg-danger-soft text-destructive',
    neutro: 'bg-crema text-foreground',
};

/** Prioridad visible de cada tarjeta (el tono lo decide el backend). */
const PRIORIDAD_ATAJO: Record<Atajo['tono'], { etiqueta: string; clase: string }> = {
    alerta: { etiqueta: 'Prioridad alta', clase: 'bg-danger-soft text-destructive' },
    oro: { etiqueta: 'Prioridad media', clase: 'bg-warning-soft text-warning' },
    primario: { etiqueta: 'Prioridad media', clase: 'bg-salvia text-primary' },
    neutro: { etiqueta: 'Prioridad normal', clase: 'bg-muted text-muted-foreground' },
};

const props = defineProps<{
    atajos: Atajo[];
    tareas: Paginado<TareaBandeja & { url: string | null }>;
    conteos: { abiertas: number; no_leidas: number; vencidas: number };
    distribucion: { por_etapa: Serie[]; por_prioridad: Serie[] };
    filtros: {
        etapa?: string | null;
        sucursal_id?: string | number | null;
        urgencia?: string | null;
        tipo?: string | null;
    };
    opciones: {
        etapas: { value: string; etiqueta: string }[];
        sucursales: { id: number; nombre: string }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: '' },
        ],
    },
});

const filtros = reactive({
    etapa: props.filtros.etapa ?? '',
    sucursal_id: props.filtros.sucursal_id
        ? String(props.filtros.sucursal_id)
        : '',
    urgencia: props.filtros.urgencia ?? '',
});

watch(filtros, () => {
    router.get(
        index.url(),
        { ...filtros },
        { preserveState: true, preserveScroll: true, replace: true },
    );
});

function alternarEtapa(clave: string) {
    filtros.etapa = filtros.etapa === clave ? '' : clave;
}

const maxEtapa = computed(() =>
    Math.max(1, ...props.distribucion.por_etapa.map((e) => e.total)),
);
const totalPrioridad = computed(() =>
    props.distribucion.por_prioridad.reduce((a, p) => a + p.total, 0),
);

// Prioridad = estado de atención: colores de estado reservados, siempre con etiqueta.
const COLOR_PRIORIDAD: Record<string, string> = {
    urgente: 'var(--mrl-danger)',
    alta: 'var(--mrl-gold)',
    media: 'var(--mrl-accent)',
    baja: 'var(--mrl-muted)',
};

function venceEn(tarea: TareaBandeja): { texto: string; clase: string } | null {
    if (tarea.vencida) {
        return {
            texto: 'Vencido',
            clase: 'bg-[var(--mrl-danger)]/10 text-[var(--mrl-danger)]',
        };
    }

    if (!tarea.vence_en) {
        return null;
    }

    const dias = Math.ceil(
        (new Date(`${tarea.vence_en}T23:59:59`).getTime() - Date.now()) /
            86_400_000,
    );

    return dias <= 3
        ? {
              texto:
                  dias <= 0
                      ? 'Vence hoy'
                      : `Vence en ${dias} día${dias === 1 ? '' : 's'}`,
              clase: 'bg-[var(--mrl-gold)]/15 text-[var(--mrl-gold-dark)]',
          }
        : {
              texto: `Vence el ${formatearFecha(tarea.vence_en)}`,
              clase: 'bg-[var(--mrl-fondo)] text-[var(--mrl-texto-suave)]',
          };
}

function iniciales(nombre: string | undefined): string {
    return (nombre ?? '?')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join('');
}

const segmento = (activo: boolean) =>
    `rounded-lg px-3 py-1.5 text-sm font-medium transition-all ${activo ? 'bg-[var(--mrl-primary)] text-white shadow-sm' : 'text-[var(--mrl-texto-suave)] hover:bg-[var(--mrl-fondo)] hover:text-[var(--mrl-texto)]'}`;
</script>

<template>
    <Head title="Mis pendientes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <!-- Encabezado con el número que importa -->
        <section
            class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-[var(--mrl-primary)] to-[var(--mrl-verde-profundo)] p-6 text-white shadow-lg sm:p-8"
        >
            <div
                aria-hidden="true"
                class="pointer-events-none absolute -top-24 -right-16 size-80 rounded-full bg-white/10"
            />
            <div
                class="relative flex flex-wrap items-end justify-between gap-6"
            >
                <div>
                    <p class="flex items-center gap-2 text-sm text-white/80">
                        <Inbox class="size-4" /> ¿Qué me toca hacer?
                    </p>
                    <p class="mt-1 text-5xl font-semibold tabular-nums">
                        {{ conteos.abiertas }}
                    </p>
                    <p class="text-sm text-white/85">
                        pendientes abiertos dentro de tu alcance
                    </p>
                </div>
                <div
                    data-tour="pendientes-conteos"
                    class="grid grid-cols-3 gap-3"
                >
                    <button
                        type="button"
                        class="rounded-2xl bg-white/10 px-4 py-3 text-left backdrop-blur transition-all hover:-translate-y-0.5 hover:bg-white/20"
                        @click="filtros.urgencia = ''"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-white/80"
                        >
                            <Inbox class="size-3.5" /> Abiertos
                        </p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ conteos.abiertas }}
                        </p>
                    </button>
                    <div
                        class="rounded-2xl bg-white/10 px-4 py-3 backdrop-blur"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-white/80"
                        >
                            <Eye class="size-3.5" /> Sin ver
                        </p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ conteos.no_leidas }}
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded-2xl px-4 py-3 text-left backdrop-blur transition-all hover:-translate-y-0.5"
                        :class="
                            conteos.vencidas
                                ? 'bg-[var(--mrl-danger)]/80 hover:bg-[var(--mrl-danger)]'
                                : 'bg-white/10 hover:bg-white/20'
                        "
                        @click="filtros.urgencia = 'vencidas'"
                    >
                        <p
                            class="flex items-center gap-1.5 text-xs text-white/90"
                        >
                            <AlarmClock class="size-3.5" /> Vencidos
                        </p>
                        <p class="text-2xl font-semibold tabular-nums">
                            {{ conteos.vencidas }}
                        </p>
                    </button>
                </div>
            </div>
        </section>

        <!-- Todo lo que espera una acción tuya, en un solo lugar -->
        <section v-if="atajos.length" aria-label="Resumen de pendientes" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <Link
                v-for="atajo in atajos"
                :key="atajo.clave"
                :href="atajo.url"
                class="tarjeta-interactiva group flex items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl text-lg font-bold tabular-nums" :class="TONO_ATAJO[atajo.tono]">
                    {{ atajo.conteo }}
                </span>
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="block text-sm font-semibold group-hover:text-primary">{{ atajo.titulo }}</span>
                    <span class="block text-xs text-muted-foreground">{{ atajo.descripcion }}</span>
                    <span class="mt-1 flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="PRIORIDAD_ATAJO[atajo.tono].clase">{{ PRIORIDAD_ATAJO[atajo.tono].etiqueta }}</span>
                        <span class="text-xs font-semibold text-primary group-hover:underline">Resolver →</span>
                    </span>
                </span>
            </Link>
        </section>

        <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <!-- Lista -->
            <div class="flex flex-col gap-4">
                <div
                    data-tour="pendientes-filtros"
                    class="flex flex-wrap items-center gap-2"
                >
                    <div
                        class="flex rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-1"
                        role="group"
                        aria-label="Urgencia"
                    >
                        <button
                            type="button"
                            :class="segmento(filtros.urgencia === '')"
                            @click="filtros.urgencia = ''"
                        >
                            Todos
                        </button>
                        <button
                            type="button"
                            :class="segmento(filtros.urgencia === 'urgentes')"
                            @click="filtros.urgencia = 'urgentes'"
                        >
                            Urgentes
                        </button>
                        <button
                            type="button"
                            :class="segmento(filtros.urgencia === 'vencidas')"
                            @click="filtros.urgencia = 'vencidas'"
                        >
                            Vencidos
                        </button>
                    </div>
                    <SelectSimple
                        v-model="filtros.etapa"
                        :opciones="
                            opciones.etapas.map((e) => ({
                                value: e.value,
                                label: e.etiqueta,
                            }))
                        "
                        opcion-vacia="Todas las etapas"
                        class="h-10 w-52"
                        aria-label="Etapa"
                    />
                    <SelectSimple
                        v-if="opciones.sucursales.length > 1"
                        v-model="filtros.sucursal_id"
                        :opciones="
                            opciones.sucursales.map((s) => ({
                                value: String(s.id),
                                label: s.nombre,
                            }))
                        "
                        opcion-vacia="Todas las sucursales"
                        class="h-10 w-56"
                        aria-label="Sucursal"
                    />
                </div>

                <div
                    v-if="!tareas.data.length"
                    class="flex flex-col items-center gap-3 rounded-3xl border border-dashed border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-12 text-center"
                >
                    <CheckCircle2 class="size-12 text-[var(--mrl-success)]" />
                    <p class="text-lg font-semibold text-[var(--mrl-texto)]">
                        ¡Todo al día!
                    </p>
                    <p class="text-sm text-[var(--mrl-texto-suave)]">
                        No tienes pendientes con estos filtros.
                    </p>
                </div>

                <ul data-tour="pendientes-lista" class="flex flex-col gap-3">
                    <li v-for="tarea in tareas.data" :key="tarea.id">
                        <component
                            :is="tarea.url ? Link : 'div'"
                            :href="tarea.url ?? undefined"
                            class="group relative flex items-center gap-4 overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4 pl-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-[var(--mrl-primary)]/40 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none"
                        >
                            <span
                                class="absolute inset-y-0 left-0 w-1.5"
                                :style="{
                                    background:
                                        COLOR_PRIORIDAD[tarea.prioridad] ??
                                        COLOR_PRIORIDAD.media,
                                }"
                            />
                            <span
                                class="flex size-11 shrink-0 items-center justify-center rounded-full bg-[var(--mrl-primary)]/10 text-sm font-semibold text-[var(--mrl-primary)] transition-colors group-hover:bg-[var(--mrl-primary)] group-hover:text-white"
                            >
                                {{
                                    iniciales(
                                        tarea.candidato?.nombre ??
                                            tarea.colaborador?.nombre,
                                    )
                                }}
                            </span>
                            <div class="min-w-0 flex-1">
                                <div
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <span
                                        v-if="tarea.read_at === null"
                                        class="size-2 rounded-full bg-[var(--mrl-accent)]"
                                        title="Sin ver"
                                    />
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-[11px] font-medium text-[var(--mrl-texto)]"
                                    >
                                        <Flame
                                            v-if="tarea.prioridad === 'urgente'"
                                            class="size-3 text-[var(--mrl-danger)]"
                                        />
                                        {{ tarea.prioridad_etiqueta }}
                                    </span>
                                    <span
                                        v-if="tarea.etapa_etiqueta"
                                        class="rounded-full bg-[var(--mrl-primary)]/10 px-2 py-0.5 text-[11px] font-medium text-[var(--mrl-primary)]"
                                        >{{ tarea.etapa_etiqueta }}</span
                                    >
                                    <span
                                        v-if="venceEn(tarea)"
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                        :class="venceEn(tarea)?.clase"
                                    >
                                        <CalendarClock class="size-3" />
                                        {{ venceEn(tarea)?.texto }}
                                    </span>
                                </div>
                                <p
                                    class="mt-1 truncate font-semibold text-[var(--mrl-texto)] group-hover:text-[var(--mrl-primary)]"
                                >
                                    {{ tarea.titulo }}
                                </p>
                                <p
                                    class="truncate text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{
                                        tarea.candidato?.nombre ??
                                        tarea.colaborador?.nombre ??
                                        '—'
                                    }}
                                    <template v-if="tarea.sucursal">
                                        · {{ tarea.sucursal }}</template
                                    >
                                    ·
                                    {{
                                        tarea.antiguedad_dias === 0
                                            ? 'hoy'
                                            : `hace ${tarea.antiguedad_dias} día${tarea.antiguedad_dias === 1 ? '' : 's'}`
                                    }}
                                </p>
                                <p
                                    v-if="tarea.descripcion"
                                    class="mt-1 line-clamp-1 text-sm text-[var(--mrl-texto-suave)]"
                                >
                                    {{ tarea.descripcion }}
                                </p>
                            </div>
                            <span
                                v-if="tarea.url"
                                class="hidden shrink-0 items-center gap-1 rounded-xl bg-[var(--mrl-primary)] px-3 py-2 text-sm font-medium text-white opacity-0 transition-all group-hover:opacity-100 sm:inline-flex"
                            >
                                Atender <ArrowRight class="size-4" />
                            </span>
                        </component>
                    </li>
                </ul>

                <nav
                    v-if="tareas.last_page > 1"
                    class="flex items-center justify-center gap-3 text-sm"
                    aria-label="Paginación"
                >
                    <Link
                        v-if="tareas.prev_page_url"
                        :href="tareas.prev_page_url"
                        preserve-scroll
                        class="rounded-lg px-3 py-1.5 hover:bg-[var(--mrl-fondo)]"
                        >Anterior</Link
                    >
                    <span class="text-[var(--mrl-texto-suave)]"
                        >Página {{ tareas.current_page }} de
                        {{ tareas.last_page }}</span
                    >
                    <Link
                        v-if="tareas.next_page_url"
                        :href="tareas.next_page_url"
                        preserve-scroll
                        class="rounded-lg px-3 py-1.5 hover:bg-[var(--mrl-fondo)]"
                        >Siguiente</Link
                    >
                </nav>
            </div>

            <!-- Gráficas -->
            <aside class="flex flex-col gap-4 xl:sticky xl:top-4 xl:self-start">
                <section
                    class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
                >
                    <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                        Pendientes por etapa
                    </h2>
                    <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                        Toca una barra para filtrar
                    </p>
                    <p
                        v-if="!distribucion.por_etapa.length"
                        class="text-sm text-[var(--mrl-texto-suave)]"
                    >
                        Sin pendientes abiertos.
                    </p>
                    <ul class="flex flex-col gap-2">
                        <li v-for="e in distribucion.por_etapa" :key="e.clave">
                            <button
                                type="button"
                                class="group grid w-full grid-cols-[minmax(0,170px)_1fr_32px] items-center gap-3 rounded-lg p-1 text-left text-sm transition-colors hover:bg-[var(--mrl-fondo)]"
                                :class="
                                    filtros.etapa === e.clave
                                        ? 'bg-[var(--mrl-primary)]/5'
                                        : ''
                                "
                                :title="`${e.etiqueta}: ${e.total}`"
                                :aria-pressed="filtros.etapa === e.clave"
                                @click="alternarEtapa(e.clave)"
                            >
                                <span
                                    class="truncate text-[var(--mrl-texto)]"
                                    >{{ e.etiqueta }}</span
                                >
                                <span
                                    class="h-3 rounded-full bg-[var(--mrl-fondo)]"
                                >
                                    <span
                                        class="block h-full rounded-r-[4px] bg-[var(--mrl-primary)] transition-all group-hover:opacity-80"
                                        :class="
                                            filtros.etapa &&
                                            filtros.etapa !== e.clave
                                                ? 'opacity-40'
                                                : ''
                                        "
                                        :style="{
                                            width: `${(e.total / maxEtapa) * 100}%`,
                                            minWidth: e.total ? '6px' : '0',
                                        }"
                                    />
                                </span>
                                <span
                                    class="text-right text-[var(--mrl-texto)] tabular-nums"
                                    >{{ e.total }}</span
                                >
                            </button>
                        </li>
                    </ul>
                </section>

                <section
                    class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
                >
                    <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                        Por prioridad
                    </h2>
                    <p class="mb-4 text-xs text-[var(--mrl-texto-suave)]">
                        {{ totalPrioridad }} abiertos
                    </p>
                    <div
                        class="flex h-4 gap-0.5 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                    >
                        <span
                            v-for="p in distribucion.por_prioridad.filter(
                                (x) => x.total,
                            )"
                            :key="p.clave"
                            class="h-full transition-opacity first:rounded-l-full last:rounded-r-full hover:opacity-80"
                            :style="{
                                width: `${(p.total / Math.max(1, totalPrioridad)) * 100}%`,
                                background: COLOR_PRIORIDAD[p.clave],
                            }"
                            :title="`${p.etiqueta}: ${p.total}`"
                        />
                    </div>
                    <ul class="mt-3 grid grid-cols-2 gap-2 text-sm">
                        <li
                            v-for="p in distribucion.por_prioridad"
                            :key="p.clave"
                            class="flex items-center gap-2"
                        >
                            <span
                                class="size-2.5 rounded-full"
                                :style="{
                                    background: COLOR_PRIORIDAD[p.clave],
                                }"
                            />
                            <span class="text-[var(--mrl-texto-suave)]">{{
                                p.etiqueta
                            }}</span>
                            <span
                                class="ml-auto font-medium text-[var(--mrl-texto)] tabular-nums"
                                >{{ p.total }}</span
                            >
                        </li>
                    </ul>
                </section>
            </aside>
        </div>
    </div>
</template>
