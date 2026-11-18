<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    Award,
    BarChart3,
    CalendarCheck2,
    ClipboardCheck,
    Gauge,
    Hourglass,
    ListChecks,
    LoaderCircle,
    Search,
    Settings2,
    Sparkles,
    Target,
    UserCheck,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvatarPersona from '@/components/evaluaciones-tecnicas/AvatarPersona.vue';
import ChipEstadoTecnica from '@/components/evaluaciones-tecnicas/ChipEstadoTecnica.vue';
import TarjetaKpi from '@/components/Seguimiento/TarjetaKpi.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { VueApexCharts } from '@/lib/apexDiferido';
import { textoPlazo } from '@/lib/evaluacionesTecnicas';
import { formatearFecha } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    colaborador as verColaborador,
    configuracion,
    contestar,
    index,
    programar,
    show,
} from '@/routes/evaluaciones-tecnicas';
import type {
    FilaEvaluacionTecnica,
    FiltrosEvaluacionesTecnicas,
    TableroEvaluacionesTecnicas,
} from '@/types';

const props = defineProps<{
    filtros: FiltrosEvaluacionesTecnicas;
    puestos: OpcionBuscable[];
    sucursales: OpcionBuscable[];
    puedeConfigurar: boolean;
    porEvaluar: FilaEvaluacionTecnica[];
    tablero?: TableroEvaluacionesTecnicas;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento', href: '' },
            { title: 'Evaluaciones técnicas', href: '' },
        ],
    },
});

// ---- Filtros (se aplican solos; la tabla se recarga sin perder la página)
const f = reactive({
    buscar: props.filtros.buscar ?? '',
    estado: props.filtros.estado,
    puesto: props.filtros.puesto,
    sucursal: props.filtros.sucursal,
    contesta: props.filtros.contesta,
});
const cargando = ref(false);

function aplicar(): void {
    const query: Record<string, string | number> = {};

    if (f.buscar.trim()) {
        query.buscar = f.buscar.trim();
    }

    for (const llave of ['estado', 'puesto', 'sucursal', 'contesta'] as const) {
        if (f[llave] !== null && f[llave] !== '') {
            query[llave] = f[llave] as string | number;
        }
    }

    router.get(index.url({ query }), {}, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['tablero', 'filtros'],
        onStart: () => (cargando.value = true),
        onFinish: () => (cargando.value = false),
    });
}

const aplicarConPausa = useDebounceFn(aplicar, 350);
watch(() => f.buscar, aplicarConPausa);
watch(() => [f.estado, f.puesto, f.sucursal, f.contesta], aplicar);

const hayFiltros = computed(
    () =>
        f.buscar.trim() !== '' ||
        f.estado !== null ||
        f.puesto !== null ||
        f.sucursal !== null ||
        f.contesta !== null,
);

function limpiar(): void {
    Object.assign(f, {
        buscar: '',
        estado: null,
        puesto: null,
        sucursal: null,
        contesta: null,
    });
}

function irPagina(url: string | null): void {
    if (url) {
        router.get(url, {}, {
            preserveState: true,
            preserveScroll: true,
            only: ['tablero'],
            onStart: () => (cargando.value = true),
            onFinish: () => (cargando.value = false),
        });
    }
}

const ESTADOS: OpcionBuscable[] = [
    { value: 'pendiente', label: 'Pendientes' },
    { value: 'vencida', label: 'Vencidas' },
    { value: 'aprobada', label: 'Aprobadas' },
    { value: 'no_aprobada', label: 'No aprobadas' },
    { value: 'cancelada', label: 'Canceladas' },
];
const CONTESTA: OpcionBuscable[] = [
    { value: 'colaborador', label: 'La contesta el colaborador' },
    { value: 'jefe_directo', label: 'La contesta el jefe directo' },
];

// ---- Generar ahora
const generando = ref(false);

function generarAhora(): void {
    router.post(programar.url(), {}, {
        preserveScroll: true,
        onStart: () => (generando.value = true),
        onFinish: () => (generando.value = false),
    });
}

// ---- Gráficas (tokens de la paleta, nunca hex)
const porPuesto = computed(() =>
    (props.tablero?.por_puesto ?? []).filter((p) => p.aprobacion !== null),
);
const opcionesPuesto = computed(() => ({
    chart: {
        toolbar: { show: false },
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 500 },
    },
    colors: ['var(--chart-2)'],
    plotOptions: {
        bar: { horizontal: true, borderRadius: 6, barHeight: '58%' },
    },
    dataLabels: {
        enabled: true,
        formatter: (v: number) => `${v}%`,
        style: { fontSize: '11px' },
    },
    grid: { borderColor: 'var(--border)', strokeDashArray: 4 },
    xaxis: {
        categories: porPuesto.value.map((p) => p.nombre),
        max: 100,
        labels: { formatter: (v: string) => `${v}%` },
    },
    tooltip: {
        y: {
            formatter: (v: number, o?: { dataPointIndex?: number }) => {
                const p = porPuesto.value[o?.dataPointIndex ?? -1];

                return `${v}% · ${p?.aprobadas ?? 0} de ${p?.contestadas ?? 0} aprobadas`;
            },
        },
    },
}));
const seriePuesto = computed(() => [
    { name: 'Aprobación', data: porPuesto.value.map((p) => p.aprobacion ?? 0) },
]);

const porDia = computed(() => props.tablero?.por_dia ?? []);
const opcionesDia = computed(() => ({
    chart: {
        toolbar: { show: false },
        fontFamily: 'inherit',
        animations: { enabled: true, speed: 500 },
    },
    colors: ['var(--chart-1)', 'var(--chart-3)'],
    stroke: { width: [0, 3], curve: 'smooth' as const },
    plotOptions: { bar: { borderRadius: 6, columnWidth: '45%' } },
    dataLabels: { enabled: false },
    legend: { position: 'top' as const, horizontalAlign: 'right' as const },
    grid: { borderColor: 'var(--border)', strokeDashArray: 4 },
    xaxis: { categories: porDia.value.map((d) => d.nombre) },
    yaxis: { max: 100, min: 0, labels: { formatter: (v: number) => `${Math.round(v)}` } },
    markers: { size: 5 },
}));
const serieDia = computed(() => [
    { name: 'Promedio', type: 'column', data: porDia.value.map((d) => d.promedio ?? 0) },
    { name: 'Aprobación %', type: 'line', data: porDia.value.map((d) => d.aprobacion ?? 0) },
]);

function hrefFila(fila: FilaEvaluacionTecnica): string {
    return fila.id === null ? '#' : show.url(fila.id);
}
</script>

<template>
    <Head title="Evaluaciones técnicas" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader
            titulo="Evaluaciones técnicas"
            descripcion="Seguimiento 15, 30, 60… por puesto desde la fecha de ingreso."
            :icono="ClipboardCheck"
        >
            <template v-if="puedeConfigurar">
                <Button data-tour="seguimientos-generar" variant="outline" :disabled="generando" @click="generarAhora">
                    <LoaderCircle v-if="generando" class="size-4 animate-spin" />
                    <Sparkles v-else class="size-4" />
                    Generar las que ya tocan
                </Button>
                <Button as-child data-tour="seguimientos-ir-calendario">
                    <Link :href="configuracion()">
                        <Settings2 class="size-4" /> Calendario por puesto
                    </Link>
                </Button>
            </template>
        </CrudPageHeader>

        <!-- Por evaluar (jefe directo) -->
        <section
            v-if="porEvaluar.length"
            data-tour="seguimientos-por-evaluar"
            class="flex entrada-suave flex-col gap-3 rounded-3xl border border-border bg-gradient-to-br from-salvia/70 via-card to-card p-5 shadow-xs"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 font-semibold">
                    <UserCheck class="size-5 text-primary" />
                    Te toca evaluar a tu equipo
                    <span
                        class="rounded-full bg-primary px-2 py-0.5 text-xs font-semibold text-primary-foreground tabular-nums"
                        >{{ porEvaluar.length }}</span
                    >
                </h2>
                <p class="text-xs text-muted-foreground">
                    El organigrama te asignó como su jefe directo.
                </p>
            </div>
            <ListaAnimada
                tag="div"
                :cantidad="porEvaluar.length"
                class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4"
            >
                <Link
                    v-for="e in porEvaluar"
                    :key="e.id ?? e.nombre"
                    :href="contestar.url(e.id as number)"
                    class="tarjeta-interactiva flex items-center gap-3 rounded-2xl border border-border bg-card p-3"
                >
                    <AvatarPersona
                        :nombre="e.colaborador?.nombre ?? ''"
                        :foto-url="e.colaborador?.foto_url"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ e.colaborador?.nombre }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ e.nombre }} ·
                            <span
                                :class="e.estado === 'vencida' ? 'text-destructive' : ''"
                                >{{ textoPlazo(e.dias_restantes) }}</span
                            >
                        </p>
                    </div>
                </Link>
            </ListaAnimada>
        </section>

        <div data-tour="seguimientos-contenido" class="flex flex-col gap-5">
        <Deferred data="tablero">
            <template #fallback>
                <div class="flex flex-col gap-5" aria-busy="true" aria-label="Cargando evaluaciones técnicas">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-6">
                        <Skeleton v-for="i in 6" :key="i" class="h-28 rounded-2xl" />
                    </div>
                    <div class="grid gap-4 xl:grid-cols-2">
                        <Skeleton class="h-80 rounded-2xl" />
                        <Skeleton class="h-80 rounded-2xl" />
                    </div>
                    <Skeleton class="h-96 rounded-2xl" />
                </div>
            </template>

            <template v-if="tablero">
                <!-- KPIs -->
                <div data-tour="seguimientos-kpis" class="grid entrada-escalonada grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <TarjetaKpi etiqueta="Pendientes en plazo" :valor="tablero.kpis.pendientes" :icono="ListChecks" nota="Por contestar" />
                    <TarjetaKpi etiqueta="Próximas a vencer" :valor="tablero.kpis.por_vencer" :icono="AlarmClock" tono="crema" nota="En los próximos días" />
                    <TarjetaKpi etiqueta="Vencidas" :valor="tablero.kpis.vencidas" :icono="Hourglass" :tono="tablero.kpis.vencidas ? 'crema' : 'card'" nota="Sin contestar a tiempo" />
                    <TarjetaKpi etiqueta="Contestadas (30 días)" :valor="tablero.kpis.contestadas_30" :icono="CalendarCheck2" tono="salvia" />
                    <TarjetaKpi etiqueta="Aprobación" :valor="tablero.kpis.aprobacion" :icono="Award" sufijo="%" :decimales="1" tono="menta" nota="Contestadas que alcanzan la mínima" />
                    <TarjetaKpi etiqueta="Promedio" :valor="tablero.kpis.promedio" :icono="Gauge" :decimales="1" nota="Calificación 0-100" />
                </div>

                <!-- Gráficas -->
                <div class="grid gap-4 xl:grid-cols-2">
                    <section class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-5 shadow-xs">
                        <h2 class="flex items-center gap-2 text-sm font-semibold">
                            <Target class="size-4 text-bronce" /> Aprobación por puesto
                        </h2>
                        <VueApexCharts
                            v-if="porPuesto.length"
                            type="bar"
                            :height="Math.max(220, porPuesto.length * 42)"
                            :options="opcionesPuesto"
                            :series="seriePuesto"
                        />
                        <EmptyState
                            v-else
                            :icono="BarChart3"
                            titulo="Aún no hay evaluaciones contestadas"
                            descripcion="Cuando se contesten las primeras, aquí verás qué puestos aprueban más."
                        />
                    </section>
                    <section class="flex flex-col gap-2 rounded-2xl border border-border bg-card p-5 shadow-xs">
                        <h2 class="flex items-center gap-2 text-sm font-semibold">
                            <BarChart3 class="size-4 text-bronce" /> Cómo avanzan del día 15 a la técnica final
                        </h2>
                        <VueApexCharts
                            v-if="porDia.length"
                            type="line"
                            height="280"
                            :options="opcionesDia"
                            :series="serieDia"
                        />
                        <EmptyState
                            v-else
                            :icono="CalendarCheck2"
                            titulo="Sin resultados por día todavía"
                            descripcion="Compara el promedio de Seguimiento 15, 30, 60… en cuanto haya resultados."
                        />
                    </section>
                </div>

                <!-- Próximas a vencer / vencidas -->
                <div data-tour="seguimientos-vencimientos" class="grid gap-4 xl:grid-cols-2">
                    <section
                        v-for="bloque in [
                            { clave: 'por_vencer', titulo: 'Próximas a vencer', icono: AlarmClock, filas: tablero.por_vencer, vacio: 'Nada vence pronto. ¡Buen ritmo!' },
                            { clave: 'vencidas', titulo: 'Vencidas', icono: Hourglass, filas: tablero.vencidas, vacio: 'No hay evaluaciones vencidas.' },
                        ]"
                        :key="bloque.clave"
                        class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5 shadow-xs"
                    >
                        <h2 class="flex items-center gap-2 text-sm font-semibold">
                            <component :is="bloque.icono" :class="cn('size-4', bloque.clave === 'vencidas' ? 'text-destructive' : 'text-bronce')" />
                            {{ bloque.titulo }}
                        </h2>
                        <ListaAnimada v-if="bloque.filas.length" :cantidad="bloque.filas.length" class="flex flex-col gap-1.5">
                            <li v-for="e in bloque.filas" :key="e.id ?? e.nombre">
                                <Link
                                    :href="hrefFila(e)"
                                    class="flex items-center gap-3 rounded-xl px-2 py-2 transition-colors hover:bg-muted/50"
                                >
                                    <AvatarPersona :nombre="e.colaborador?.nombre ?? ''" :foto-url="e.colaborador?.foto_url" tamano="sm" />
                                    <div class="min-w-0 flex-1">
                                        <p class="truncate text-sm font-medium">{{ e.colaborador?.nombre }}</p>
                                        <p class="truncate text-xs text-muted-foreground">
                                            {{ e.nombre }} · {{ e.puesto }} · {{ e.contesta_etiqueta }}
                                        </p>
                                    </div>
                                    <span
                                        :class="cn('shrink-0 text-xs font-medium tabular-nums', e.estado === 'vencida' ? 'text-destructive' : 'text-warning')"
                                        >{{ textoPlazo(e.dias_restantes) }}</span
                                    >
                                </Link>
                            </li>
                        </ListaAnimada>
                        <p v-else class="rounded-xl bg-muted/40 px-3 py-6 text-center text-sm text-muted-foreground">
                            {{ bloque.vacio }}
                        </p>
                    </section>
                </div>

                <!-- Tabla con filtros -->
                <section data-tour="seguimientos-tabla" class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5 shadow-xs">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <h2 class="text-sm font-semibold">
                            Todas las evaluaciones
                            <span class="font-normal text-muted-foreground tabular-nums">· {{ tablero.lista.total }}</span>
                        </h2>
                        <Button v-if="hayFiltros" variant="ghost" size="sm" @click="limpiar">Quitar filtros</Button>
                    </div>
                    <div data-tour="seguimientos-filtros" class="grid gap-2 sm:grid-cols-2 xl:grid-cols-5">
                        <label class="relative xl:col-span-1">
                            <span class="sr-only">Buscar</span>
                            <Search class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="f.buscar" class="pl-9" placeholder="Colaborador o evaluación…" />
                        </label>
                        <ComboboxBuscable v-model="f.estado" :opciones="ESTADOS" placeholder="Todos los estados" limpiable aria-label="Estado" />
                        <ComboboxBuscable v-model="f.puesto" :opciones="puestos" placeholder="Todos los puestos" limpiable numerico aria-label="Puesto" />
                        <ComboboxBuscable v-model="f.sucursal" :opciones="sucursales" placeholder="Todas las sucursales" limpiable numerico aria-label="Sucursal" />
                        <ComboboxBuscable v-model="f.contesta" :opciones="CONTESTA" placeholder="Quién contesta" limpiable aria-label="Quién contesta" />
                    </div>

                    <CambioSuave>
                        <div v-if="cargando" key="cargando" class="flex flex-col gap-2">
                            <Skeleton v-for="i in 6" :key="i" class="h-14 rounded-xl" />
                        </div>
                        <EmptyState
                            v-else-if="tablero.lista.data.length === 0"
                            key="vacio"
                            :icono="ClipboardCheck"
                            :titulo="hayFiltros ? 'Sin coincidencias' : 'Todavía no hay evaluaciones técnicas'"
                            :descripcion="
                                hayFiltros
                                    ? 'Prueba con otros filtros.'
                                    : puedeConfigurar
                                      ? 'Configura el calendario de un puesto (p. ej. Gestor: Seguimiento 15, 30 y 60) y se generarán solas al llegar cada día.'
                                      : 'Cuando el calendario de un puesto genere evaluaciones, aparecerán aquí.'
                            "
                        />
                        <div v-else key="tabla" class="scroll-x-limpio -mx-5 px-5">
                            <table class="w-full min-w-[820px] text-sm">
                                <thead>
                                    <tr class="border-b border-border text-left text-xs text-muted-foreground">
                                        <th class="py-2 pr-3 font-medium">Colaborador</th>
                                        <th class="px-3 py-2 font-medium">Evaluación</th>
                                        <th class="px-3 py-2 font-medium">Contesta</th>
                                        <th class="px-3 py-2 font-medium">Fecha límite</th>
                                        <th class="px-3 py-2 text-right font-medium">Calificación</th>
                                        <th class="py-2 pl-3 font-medium">Estado</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="e in tablero.lista.data"
                                        :key="e.id ?? e.nombre"
                                        class="cursor-pointer border-b border-border/60 transition-colors last:border-0 hover:bg-muted/40"
                                        @click="router.visit(hrefFila(e))"
                                    >
                                        <td class="py-2.5 pr-3">
                                            <div class="flex items-center gap-2.5">
                                                <AvatarPersona :nombre="e.colaborador?.nombre ?? ''" :foto-url="e.colaborador?.foto_url" tamano="sm" />
                                                <div class="min-w-0">
                                                    <Link
                                                        v-if="e.colaborador"
                                                        :href="verColaborador.url(e.colaborador.id)"
                                                        class="block truncate font-medium hover:underline"
                                                        @click.stop
                                                        >{{ e.colaborador.nombre }}</Link
                                                    >
                                                    <p class="truncate text-xs text-muted-foreground">
                                                        {{ e.puesto }}<template v-if="e.colaborador?.sucursal"> · {{ e.colaborador.sucursal }}</template>
                                                    </p>
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2.5">
                                            <p class="font-medium">{{ e.nombre }}</p>
                                            <p class="text-xs text-muted-foreground">Día {{ e.dias }}<template v-if="e.es_final"> · técnica final</template></p>
                                        </td>
                                        <td class="px-3 py-2.5">
                                            {{ e.contesta_etiqueta }}
                                            <p v-if="e.contesta === 'jefe_directo' && e.evaluador" class="text-xs text-muted-foreground">{{ e.evaluador }}</p>
                                        </td>
                                        <td class="px-3 py-2.5 tabular-nums">
                                            {{ formatearFecha(e.fecha_limite) }}
                                            <p
                                                v-if="e.estado === 'pendiente' || e.estado === 'vencida'"
                                                :class="cn('text-xs', e.estado === 'vencida' ? 'text-destructive' : 'text-muted-foreground')"
                                            >
                                                {{ textoPlazo(e.dias_restantes) }}
                                            </p>
                                        </td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">
                                            <template v-if="e.calificacion !== null">
                                                <span class="font-semibold">{{ e.calificacion }}</span>
                                                <span class="text-xs text-muted-foreground"> / mín. {{ e.calificacion_minima }}</span>
                                            </template>
                                            <span v-else class="text-muted-foreground">—</span>
                                        </td>
                                        <td class="py-2.5 pl-3"><ChipEstadoTecnica :estado="e.estado" /></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </CambioSuave>

                    <nav
                        v-if="tablero.lista.last_page > 1"
                        class="flex flex-wrap items-center justify-between gap-2 text-sm"
                        aria-label="Paginación"
                    >
                        <span class="text-muted-foreground tabular-nums">
                            {{ tablero.lista.from }}–{{ tablero.lista.to }} de {{ tablero.lista.total }}
                        </span>
                        <div class="flex flex-wrap gap-1">
                            <Button
                                v-for="(l, i) in tablero.lista.links"
                                :key="i"
                                size="sm"
                                :variant="l.active ? 'default' : 'ghost'"
                                :disabled="!l.url"
                                @click="irPagina(l.url)"
                            >
                                <span v-html="l.label" />
                            </Button>
                        </div>
                    </nav>
                </section>
            </template>
        </Deferred>
        </div>
    </div>
</template>
