<script setup lang="ts">
import { getLocalTimeZone, parseDate, today } from '@internationalized/date';
import type { DateValue } from '@internationalized/date';
import {
    Briefcase,
    CalendarIcon,
    FileSpreadsheet,
    FileText,
    TrendingDown,
    TrendingUp,
    UserRound,
    Users,
} from '@lucide/vue';
import type { DateRange } from 'reka-ui';
import { computed, ref, shallowRef, watch } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { RangeCalendar } from '@/components/ui/range-calendar';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { getJson } from '@/lib/http';
import { rotacion } from '@/routes/dashboard';
import { excel as exportarExcel, pdf as exportarPdf } from '@/routes/dashboard/rotacion';
import type { DepartamentoFiltro, RotacionPersonalData, SucursalFiltro } from '@/types';

const props = defineProps<{
    datosIniciales: RotacionPersonalData;
    sucursales: SucursalFiltro[];
    departamentos: DepartamentoFiltro[];
}>();

const datos = ref<RotacionPersonalData>(props.datosIniciales);
const cargando = ref(false);

// reka-ui/Radix prohíben value="" en un <SelectItem> (esa cadena está
// reservada para "sin selección"): por eso el select de "Todas" no
// respondía al click. Se usa un valor centinela y se traduce a "" solo al
// armar la query real hacia el backend.
const TODAS = '__todas__';
const TODOS = '__todos__';
const sucursalId = ref(TODAS);
const departamentoId = ref(TODOS);

const desde = ref(props.datosIniciales.periodo.desde);
const hasta = ref(props.datosIniciales.periodo.hasta);

// Calendario real de shadcn (RangeCalendar sobre reka-ui), no un
// <input type="date"> nativo del navegador.
//
// shallowRef, no ref: los objetos DateValue de @internationalized/date usan
// campos privados de clase (#privado). El proxy reactivo profundo de `ref`
// los envuelve y rompe el acceso a esos privados, así que al tocar el
// segundo día el picker perdía el primero y "se reiniciaba" — con
// shallowRef el objeto { start, end } no se envuelve en profundidad y las
// instancias de fecha quedan intactas.
const rango = shallowRef<DateRange>({
    start: parseDate(desde.value),
    end: parseDate(hasta.value),
});
const calendarioAbierto = ref(false);

function formatearFecha(valor: DateValue | undefined): string {
    if (!valor) {
        return '';
    }

    return valor.toDate(getLocalTimeZone()).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

const etiquetaRango = computed(() => {
    if (!rango.value.start || !rango.value.end) {
        return 'Selecciona un rango';
    }

    return `${formatearFecha(rango.value.start)} – ${formatearFecha(rango.value.end)}`;
});

watch(rango, (valor) => {
    if (valor.start && valor.end) {
        desde.value = valor.start.toString();
        hasta.value = valor.end.toString();
    }
});

async function recargar() {
    cargando.value = true;

    try {
        datos.value = await getJson<RotacionPersonalData>(
            rotacion.url({
                query: {
                    sucursal_id: sucursalId.value === TODAS ? undefined : sucursalId.value,
                    departamento_id: departamentoId.value === TODOS ? undefined : departamentoId.value,
                    desde: desde.value || undefined,
                    hasta: hasta.value || undefined,
                },
            }),
        );
    } finally {
        cargando.value = false;
    }
}

watch([sucursalId, departamentoId, desde, hasta], recargar);

// Rangos rápidos (mismo patrón que Rh/Cumpleanos/Index.vue): evita que RH
// tenga que escribir fechas a mano para lo más común.
const RANGOS_RAPIDOS = [
    { etiqueta: '7 días', dias: 7 },
    { etiqueta: '30 días', dias: 30 },
    { etiqueta: '90 días', dias: 90 },
    { etiqueta: '180 días', dias: 180 },
];

function aplicarRangoRapido(dias: number) {
    const hoy = today(getLocalTimeZone());
    const inicio = hoy.subtract({ days: dias });

    rango.value = { start: inicio, end: hoy };
    desde.value = inicio.toString();
    hasta.value = hoy.toString();
}

const rangoActivo = computed(() => {
    const dias = Math.round(
        (new Date(hasta.value).getTime() - new Date(desde.value).getTime()) /
            (1000 * 60 * 60 * 24),
    );

    return RANGOS_RAPIDOS.find((r) => r.dias === dias)?.dias ?? null;
});

const paletaMarca = [
    'var(--brand-primary)',
    'var(--brand-secondary)',
    '#f59e0b',
    '#10b981',
    '#6366f1',
];

const opcionesBase = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit' },
    colors: paletaMarca,
    dataLabels: { enabled: false },
    legend: { position: 'bottom' as const },
    grid: { borderColor: 'var(--border)' },
}));

const tendenciaSeries = computed(() => [
    { name: 'Altas', data: datos.value.tendenciaMensual.map((m) => m.altas) },
    { name: 'Bajas', data: datos.value.tendenciaMensual.map((m) => m.bajas) },
]);
const tendenciaOpciones = computed(() => ({
    ...opcionesBase.value,
    chart: { ...opcionesBase.value.chart, type: 'bar' as const },
    xaxis: { categories: datos.value.tendenciaMensual.map((m) => m.mes) },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
    colors: ['var(--brand-primary)', 'var(--destructive)'],
}));

const sucursalesSeries = computed(() => [
    {
        name: 'Altas',
        data: datos.value.altasPorSucursal.slice(0, 8).map((s) => s.valor),
    },
]);
const sucursalesCategorias = computed(() =>
    datos.value.altasPorSucursal.slice(0, 8).map((s) => s.etiqueta),
);
const bajasSucursalesSeries = computed(() => [
    {
        name: 'Bajas',
        data: datos.value.bajasPorSucursal.slice(0, 8).map((s) => s.valor),
    },
]);
const bajasSucursalesCategorias = computed(() =>
    datos.value.bajasPorSucursal.slice(0, 8).map((s) => s.etiqueta),
);

const departamentoSeries = computed(() => [
    {
        name: 'Colaboradores',
        data: datos.value.porDepartamento.map((d) => d.valor),
    },
]);
const departamentoCategorias = computed(() =>
    datos.value.porDepartamento.map((d) => d.etiqueta),
);

const generoSeries = computed(() =>
    datos.value.genero.map((g) => g.valor),
);
const generoEtiquetas = computed(() => datos.value.genero.map((g) => g.etiqueta));
const generoOpciones = computed(() => ({
    ...opcionesBase.value,
    labels: generoEtiquetas.value,
    colors: ['#6366f1', '#ec4899', '#94a3b8'],
}));

// KPIs de género como cifra directa (no solo dentro de la dona): cuenta y
// % sobre la plantilla actual, redondeado a 1 decimal.
function porcentaje(valor: number): string {
    return datos.value.plantilla_actual > 0
        ? `${((valor / datos.value.plantilla_actual) * 100).toFixed(1)}%`
        : '0%';
}

const hombres = computed(
    () => datos.value.genero.find((g) => g.etiqueta === 'Masculino')?.valor ?? 0,
);
const mujeres = computed(
    () => datos.value.genero.find((g) => g.etiqueta === 'Femenino')?.valor ?? 0,
);

const queryExportacion = computed(() => ({
    sucursal_id: sucursalId.value === TODAS ? undefined : sucursalId.value,
    departamento_id: departamentoId.value === TODOS ? undefined : departamentoId.value,
    desde: desde.value,
    hasta: hasta.value,
}));
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-1.5">
                <Label class="text-xs">Sucursal</Label>
                <Select v-model="sucursalId">
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todas"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="TODAS">Todas las sucursales</SelectItem>
                        <SelectItem
                            v-for="s in sucursales"
                            :key="s.id"
                            :value="String(s.id)"
                            >{{ s.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label class="text-xs">Departamento</Label>
                <Select v-model="departamentoId">
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todos"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="TODOS">Todos los departamentos</SelectItem>
                        <SelectItem
                            v-for="d in departamentos"
                            :key="d.id"
                            :value="String(d.id)"
                            >{{ d.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label class="text-xs">Periodo</Label>
                <Popover v-model:open="calendarioAbierto">
                    <PopoverTrigger as-child>
                        <Button
                            variant="outline"
                            class="w-64 justify-start text-left font-normal"
                        >
                            <CalendarIcon class="size-4 text-muted-foreground" />
                            {{ etiquetaRango }}
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-auto p-0" align="start">
                        <RangeCalendar
                            v-model="rango"
                            locale="es-MX"
                            :max-value="today(getLocalTimeZone())"
                            :week-starts-on="1"
                        />
                    </PopoverContent>
                </Popover>
            </div>
            <div class="flex flex-wrap gap-1.5 pb-0.5">
                <button
                    v-for="opcion in RANGOS_RAPIDOS"
                    :key="opcion.dias"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-xs transition-colors"
                    :class="
                        rangoActivo === opcion.dias
                            ? 'border-[var(--brand-primary)]/40 bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]'
                            : 'text-muted-foreground hover:bg-accent hover:text-foreground'
                    "
                    @click="aplicarRangoRapido(opcion.dias)"
                >
                    {{ opcion.etiqueta }}
                </button>
            </div>

            <div class="ml-auto flex gap-2">
                <a
                    :href="exportarExcel.url({ query: queryExportacion })"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border/60 px-3 py-2 text-sm font-medium transition-all hover:border-primary/40 hover:bg-accent hover:shadow-sm"
                >
                    <FileSpreadsheet class="size-4" />
                    Excel
                </a>
                <a
                    :href="exportarPdf.url({ query: queryExportacion })"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-border/60 px-3 py-2 text-sm font-medium transition-all hover:border-primary/40 hover:bg-accent hover:shadow-sm"
                >
                    <FileText class="size-4" />
                    PDF
                </a>
            </div>
        </div>

        <div v-if="cargando" class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <Skeleton v-for="i in 8" :key="i" class="h-24 rounded-2xl" />
        </div>
        <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <MetricCard
                titulo="Plantilla total"
                :valor="datos.plantilla_actual"
                :icono="Users"
                tono="success"
            />
            <MetricCard
                titulo="Vacantes disponibles"
                :valor="datos.eficiencia.vacantes"
                :icono="Briefcase"
                tono="warning"
            />
            <MetricCard
                titulo="Cumplimiento headcount"
                :valor="`${datos.eficiencia.cumplimiento}%`"
                :subvalor="`${datos.eficiencia.plantilla_actual} de ${datos.eficiencia.plantilla_autorizada}`"
            />
            <MetricCard
                titulo="Rotación"
                :valor="`${datos.rotacion_porcentaje}%`"
                :icono="TrendingUp"
                tono="warning"
            />
            <MetricCard
                titulo="Altas del periodo"
                :valor="datos.altas"
                :icono="TrendingUp"
                tono="success"
            />
            <MetricCard
                titulo="Bajas del periodo"
                :valor="datos.bajas"
                :icono="TrendingDown"
                tono="danger"
            />
            <MetricCard
                titulo="Hombres"
                :valor="hombres"
                :subvalor="porcentaje(hombres)"
                :icono="UserRound"
            />
            <MetricCard
                titulo="Mujeres"
                :valor="mujeres"
                :subvalor="porcentaje(mujeres)"
                :icono="UserRound"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <div
                class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
            >
                <p class="mb-2 text-sm font-semibold">
                    Altas vs. bajas (últimos 6 meses)
                </p>
                <p
                    v-if="!datos.tendenciaMensual.some((m) => m.altas || m.bajas)"
                    class="flex h-[240px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin altas ni bajas en los últimos 6 meses.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="240"
                    :options="tendenciaOpciones"
                    :series="tendenciaSeries"
                />
            </div>
            <div
                class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
            >
                <p class="mb-2 text-sm font-semibold">
                    Colaboradores por departamento
                </p>
                <p
                    v-if="!datos.porDepartamento.length"
                    class="flex h-[240px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin colaboradores activos que mostrar.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="240"
                    :options="{
                        ...opcionesBase,
                        chart: { ...opcionesBase.chart, type: 'bar' },
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        xaxis: { categories: departamentoCategorias },
                        colors: ['var(--brand-secondary)'],
                    }"
                    :series="departamentoSeries"
                />
            </div>
            <div
                class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
            >
                <p class="mb-2 text-sm font-semibold">
                    Composición por género (activos)
                </p>
                <p
                    v-if="datos.plantilla_actual === 0"
                    class="flex h-[240px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin colaboradores activos que mostrar.
                </p>
                <VueApexCharts
                    v-else
                    type="donut"
                    height="240"
                    :options="generoOpciones"
                    :series="generoSeries"
                />
            </div>
            <div
                class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
            >
                <p class="mb-2 text-sm font-semibold">
                    Altas por sucursal (periodo)
                </p>
                <p
                    v-if="!datos.altasPorSucursal.length"
                    class="flex h-[240px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin altas en el periodo/sucursal seleccionados.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="240"
                    :options="{
                        ...opcionesBase,
                        chart: { ...opcionesBase.chart, type: 'bar' },
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        xaxis: { categories: sucursalesCategorias },
                        colors: ['var(--brand-primary)'],
                    }"
                    :series="sucursalesSeries"
                />
            </div>
            <div
                class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md lg:col-span-2"
            >
                <p class="mb-2 text-sm font-semibold">
                    Bajas por sucursal (periodo)
                </p>
                <p
                    v-if="!datos.bajasPorSucursal.length"
                    class="flex h-[240px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin bajas en el periodo/sucursal seleccionados.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="240"
                    :options="{
                        ...opcionesBase,
                        chart: { ...opcionesBase.chart, type: 'bar' },
                        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
                        xaxis: { categories: bajasSucursalesCategorias },
                        colors: ['var(--destructive)'],
                    }"
                    :series="bajasSucursalesSeries"
                />
            </div>
        </div>
    </div>
</template>
