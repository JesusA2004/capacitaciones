<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Baby,
    Banknote,
    Cake,
    Calendar,
    ClipboardList,
    Clock,
    FileEdit,
    FileWarning,
    Heart,
    Layers,
    LogOut,
    MessageSquare,
    Timer,
    UserX,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/solicitudes';

type ResumenTipo = {
    clave: string;
    etiqueta: string;
    recibidas: number;
    por_autorizar: number;
    correccion: number;
    abiertas: number;
    total: number;
    ultimo_mes: number;
};

const props = defineProps<{ tipos: ResumenTipo[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Solicitudes', href: '' },
        ],
    },
});

const ICONO: Record<string, Component> = {
    vacaciones: Calendar,
    permiso_con_goce: FileEdit,
    permiso_sin_goce: FileEdit,
    permiso_tiempo: Timer,
    salida_temprano: LogOut,
    llegada_tarde: Clock,
    incapacidad: FileWarning,
    constancia_laboral: ClipboardList,
    actualizacion_datos: FileEdit,
    actualizacion_bancaria: Banknote,
    reposicion_documental: FileWarning,
    prestamo: Banknote,
    baja_colaborador: UserX,
    permiso_especial_cumpleanos: Cake,
    permiso_especial_paternidad: Baby,
    permiso_especial_fallecimiento: Heart,
    solicitud_general: MessageSquare,
};

// Colores de la paleta institucional (variables --mrl-*), uno por grupo.
const GRUPOS: { titulo: string; tipos: string[]; color: string }[] = [
    {
        titulo: 'Tiempo y ausencias',
        tipos: [
            'vacaciones',
            'permiso_con_goce',
            'permiso_sin_goce',
            'permiso_tiempo',
            'salida_temprano',
            'llegada_tarde',
            'incapacidad',
            'permiso_especial_cumpleanos',
            'permiso_especial_paternidad',
            'permiso_especial_fallecimiento',
        ],
        color: 'var(--mrl-primary)',
    },
    {
        titulo: 'Dinero',
        tipos: ['prestamo', 'actualizacion_bancaria'],
        color: 'var(--mrl-gold)',
    },
    {
        titulo: 'Trámites y datos',
        tipos: [
            'constancia_laboral',
            'actualizacion_datos',
            'reposicion_documental',
            'solicitud_general',
        ],
        color: 'var(--mrl-navy)',
    },
    {
        titulo: 'Personal',
        tipos: ['baja_colaborador'],
        color: 'var(--mrl-danger)',
    },
];

const porClave = computed(() =>
    Object.fromEntries(props.tipos.map((t) => [t.clave, t])),
);
const totales = computed(() => ({
    recibidas: props.tipos.reduce((a, t) => a + t.recibidas, 0),
    por_autorizar: props.tipos.reduce((a, t) => a + t.por_autorizar, 0),
    correccion: props.tipos.reduce((a, t) => a + t.correccion, 0),
    ultimo_mes: props.tipos.reduce((a, t) => a + t.ultimo_mes, 0),
}));
const maxAbiertas = computed(() =>
    Math.max(1, ...props.tipos.map((t) => t.abiertas)),
);

const grupos = computed(() =>
    GRUPOS.map((g) => ({
        ...g,
        tipos: g.tipos
            .map((c) => porClave.value[c])
            .filter((t): t is ResumenTipo => t !== undefined)
            .sort(
                (a, b) =>
                    b.abiertas - a.abiertas || b.ultimo_mes - a.ultimo_mes,
            ),
    })),
);

const ancho = (n: number, total: number) => `${total ? (n / total) * 100 : 0}%`;
</script>

<template>
    <Head title="Solicitudes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <header class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1
                    class="text-2xl font-semibold tracking-tight text-[var(--mrl-texto)]"
                >
                    Solicitudes
                </h1>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    Elige qué tipo de solicitud quieres atender.
                </p>
            </div>
            <Link
                :href="index.url({ query: { todas: 1 } })"
                class="inline-flex items-center gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] px-4 py-2 text-sm font-medium transition-all hover:gap-3 hover:border-[var(--mrl-primary)] hover:text-[var(--mrl-primary)]"
            >
                <Layers class="size-4" /> Ver todas en un solo tablero
                <ArrowRight class="size-4" />
            </Link>
        </header>

        <!-- Indicadores generales -->
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4"
            >
                <p
                    class="text-xs font-medium tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Sin revisar
                </p>
                <p
                    class="text-3xl font-semibold text-[var(--mrl-accent)] tabular-nums"
                >
                    {{ totales.recibidas }}
                </p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    esperan visto bueno del gerente o regional
                </p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4"
            >
                <p
                    class="text-xs font-medium tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Pendientes de autorizar
                </p>
                <p
                    class="text-3xl font-semibold text-[var(--mrl-primary)] tabular-nums"
                >
                    {{ totales.por_autorizar }}
                </p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    con visto bueno, esperan a RH
                </p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4"
            >
                <p
                    class="text-xs font-medium tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    En corrección
                </p>
                <p
                    class="text-3xl font-semibold text-[var(--mrl-gold-dark)] tabular-nums"
                >
                    {{ totales.correccion }}
                </p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    esperan al colaborador
                </p>
            </div>
            <div
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4"
            >
                <p
                    class="text-xs font-medium tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                >
                    Últimos 30 días
                </p>
                <p
                    class="text-3xl font-semibold text-[var(--mrl-texto)] tabular-nums"
                >
                    {{ totales.ultimo_mes }}
                </p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    solicitudes nuevas
                </p>
            </div>
        </div>

        <div data-tour="solicitudes-tipos" class="flex flex-col gap-6">
            <section
                v-for="g in grupos"
                :key="g.titulo"
                class="flex flex-col gap-3"
            >
                <h2
                    class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]"
                >
                    <span
                        class="size-2.5 rounded-full"
                        :style="{ background: g.color }"
                    />
                    {{ g.titulo }}
                </h2>
                <div
                    class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                >
                    <Link
                        v-for="t in g.tipos"
                        :key="t.clave"
                        :href="index.url({ query: { tipo: t.clave } })"
                        class="group relative flex flex-col gap-3 overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none"
                        :class="
                            t.abiertas === 0 && t.ultimo_mes === 0
                                ? 'opacity-70 hover:opacity-100'
                                : ''
                        "
                    >
                        <span
                            class="absolute inset-x-0 top-0 h-1 transition-all group-hover:h-1.5"
                            :style="{ background: g.color }"
                        />
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-11 shrink-0 items-center justify-center rounded-xl text-white transition-transform group-hover:scale-110"
                                :style="{ background: g.color }"
                            >
                                <component
                                    :is="ICONO[t.clave] ?? ClipboardList"
                                    class="size-5"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p
                                    class="truncate font-semibold text-[var(--mrl-texto)]"
                                >
                                    {{ t.etiqueta }}
                                </p>
                                <p
                                    class="text-xs text-[var(--mrl-texto-suave)]"
                                >
                                    {{ t.ultimo_mes }} en los últimos 30 días
                                </p>
                            </div>
                            <div class="text-right">
                                <p
                                    class="text-2xl leading-none font-semibold tabular-nums"
                                    :style="{
                                        color: t.abiertas ? g.color : undefined,
                                    }"
                                >
                                    {{ t.abiertas }}
                                </p>
                                <p
                                    class="text-[11px] text-[var(--mrl-texto-suave)]"
                                >
                                    {{
                                        t.abiertas === 1
                                            ? 'pendiente'
                                            : 'pendientes'
                                    }}
                                </p>
                            </div>
                        </div>

                        <!-- Distribución de las pendientes -->
                        <div
                            class="flex h-2 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                            :style="{
                                width: `${Math.max(12, (t.abiertas / maxAbiertas) * 100)}%`,
                            }"
                        >
                            <span
                                class="h-full bg-[var(--mrl-accent)]"
                                :style="{
                                    width: ancho(t.recibidas, t.abiertas),
                                }"
                            />
                            <span
                                class="h-full bg-[var(--mrl-primary)]"
                                :style="{
                                    width: ancho(t.por_autorizar, t.abiertas),
                                }"
                            />
                            <span
                                class="h-full bg-[var(--mrl-gold)]"
                                :style="{
                                    width: ancho(t.correccion, t.abiertas),
                                }"
                            />
                        </div>

                        <div class="flex flex-wrap gap-1.5 text-[11px]">
                            <span
                                class="rounded-full bg-[var(--mrl-accent)]/10 px-2 py-0.5 text-[var(--mrl-texto)]"
                                >{{ t.recibidas }} sin revisar</span
                            >
                            <span
                                class="rounded-full bg-[var(--mrl-primary)]/10 px-2 py-0.5 text-[var(--mrl-texto)]"
                                >{{ t.por_autorizar }} por autorizar</span
                            >
                            <span
                                v-if="t.correccion"
                                class="rounded-full bg-[var(--mrl-gold)]/15 px-2 py-0.5 text-[var(--mrl-texto)]"
                                >{{ t.correccion }} en corrección</span
                            >
                            <span
                                class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-[var(--mrl-texto-suave)]"
                                >{{ t.total }} en total</span
                            >
                            <ArrowRight
                                class="ml-auto size-4 text-[var(--mrl-texto-suave)] opacity-0 transition-all group-hover:translate-x-0.5 group-hover:opacity-100"
                            />
                        </div>
                    </Link>
                </div>
            </section>
        </div>
    </div>
</template>
