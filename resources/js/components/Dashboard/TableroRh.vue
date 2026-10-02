<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    Award,
    Briefcase,
    CalendarClock,
    Megaphone,
    RotateCcw,
    Timer,
    TrendingDown,
    Users,
    Wallet,
} from '@lucide/vue';
import { GroupedBar } from '@unovis/ts';
import {
    VisArea,
    VisAxis,
    VisCrosshair,
    VisGroupedBar,
    VisLine,
    VisTooltip,
    VisXYContainer,
} from '@unovis/vue';
import { computed, ref, watch } from 'vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { TableroRhDatos } from '@/types';

/**
 * Tablero de RH (Inicio operativo). Un solo DTO del backend
 * (TableroRhService, el mismo de la API móvil); cada filtro recarga SOLO
 * `tablero`, así KPIs, gráficas y tablas se actualizan juntos.
 *
 * Color: cada gráfica tiene su propio color (--grafica-*, configurable en
 * Administración → Configuración → Apariencia → Gráficas); "autorizada" es
 * siempre una pista neutra detrás de lo ocupado, nunca un segundo color.
 */
const props = defineProps<{ tablero: TableroRhDatos }>();

const TODAS = 'todas';

const mes = ref(props.tablero.filters.mes);
const empresa = ref(
    props.tablero.filters.empresa_id
        ? String(props.tablero.filters.empresa_id)
        : TODAS,
);
const sucursal = ref(
    props.tablero.filters.sucursal_id
        ? String(props.tablero.filters.sucursal_id)
        : TODAS,
);
const meses = ref(props.tablero.filters.meses ?? 12);
const cargando = ref(false);

const sucursalesDeEmpresa = computed(() =>
    empresa.value === TODAS
        ? props.tablero.filters.sucursales
        : props.tablero.filters.sucursales.filter(
              (s) => String(s.empresa_id) === empresa.value,
          ),
);

function filtrar() {
    router.reload({
        data: {
            tablero_mes: mes.value,
            tablero_empresa_id: empresa.value === TODAS ? null : empresa.value,
            tablero_sucursal_id:
                sucursal.value === TODAS ? null : sucursal.value,
            tablero_meses: meses.value,
        },
        only: ['tablero'],
        onStart: () => (cargando.value = true),
        onFinish: () => (cargando.value = false),
    });
}

watch(empresa, () => {
    if (
        sucursal.value !== TODAS &&
        !sucursalesDeEmpresa.value.some((s) => String(s.id) === sucursal.value)
    ) {
        sucursal.value = TODAS;
    }
});
watch([mes, empresa, sucursal, meses], filtrar);

const hayFiltros = computed(
    () =>
        empresa.value !== TODAS ||
        sucursal.value !== TODAS ||
        meses.value !== 12 ||
        mes.value !== new Date().toISOString().slice(0, 7),
);

function limpiar() {
    mes.value = new Date().toISOString().slice(0, 7);
    empresa.value = TODAS;
    sucursal.value = TODAS;
    meses.value = 12;
}

function elegirSucursal(id: number) {
    sucursal.value = String(id);
}

const moneda = new Intl.NumberFormat('es-MX', {
    style: 'currency',
    currency: 'MXN',
    maximumFractionDigits: 0,
});
const numero = new Intl.NumberFormat('es-MX');

const s = computed(() => props.tablero.summary);

const tarjetas = computed(() => [
    {
        clave: 'plantilla',
        titulo: 'Plantilla activa',
        icono: Users,
        valor: numero.format(s.value.plantilla_activa.valor),
        // Misma base que "Cobertura de plantilla": plazas autorizadas
        // ocupadas (quien está en un puesto sin plantilla no cubre plaza).
        detalle: `${numero.format(totalesCobertura.value.ocupada)} de ${numero.format(totalesCobertura.value.autorizada)} plazas autorizadas cubiertas`,
        avance: Math.min(100, totalesCobertura.value.porcentaje),
    },
    {
        clave: 'vacantes',
        titulo: 'Vacantes abiertas',
        icono: Briefcase,
        valor: numero.format(s.value.vacantes_abiertas.valor),
        detalle: `${s.value.vacantes_abiertas.sucursales} en sucursales · ${s.value.vacantes_abiertas.corporativo} corporativo`,
    },
    {
        clave: 'rotacion',
        titulo: 'Rotación del mes',
        icono: TrendingDown,
        valor: `${s.value.rotacion_mes.porcentaje}%`,
        detalle: `${s.value.rotacion_mes.bajas} ${s.value.rotacion_mes.bajas === 1 ? 'baja' : 'bajas'} en el mes`,
    },
    {
        clave: 'costo',
        titulo: 'Costo por contratación',
        icono: Wallet,
        valor:
            s.value.costo_por_contratacion.valor === null
                ? '—'
                : moneda.format(s.value.costo_por_contratacion.valor),
        detalle: 'inversión en campañas ÷ contratados',
    },
    {
        clave: 'tiempo',
        titulo: 'Tiempo de contratación',
        icono: Timer,
        valor:
            s.value.tiempo_contratacion.dias === null
                ? '—'
                : `${s.value.tiempo_contratacion.dias} días`,
        detalle: `${s.value.tiempo_contratacion.contratados} contratados en el mes`,
    },
    {
        clave: 'permanencia',
        titulo: 'Permanencia promedio',
        icono: Award,
        valor:
            s.value.permanencia_promedio.meses === null
                ? '—'
                : `${s.value.permanencia_promedio.meses} meses`,
        detalle: 'antigüedad de la plantilla vigente',
    },
    {
        clave: 'vencer',
        titulo: 'Contratos por vencer',
        icono: CalendarClock,
        valor: numero.format(s.value.contratos_por_vencer.valor),
        detalle: 'periodos de prueba · próximos 30 días',
    },
    {
        clave: 'inversion',
        titulo: 'Inversión en campañas',
        icono: Megaphone,
        valor: moneda.format(s.value.inversion_campanas_mes.valor),
        detalle: `${s.value.inversion_campanas_mes.campanas} campañas en el mes`,
    },
]);

// --- Rotación mensual (área + línea, crosshair con tooltip) ---
type MesRotacion = TableroRhDatos['turnover_monthly'][number];
const rotacion = computed(() => props.tablero.turnover_monthly);
const xIndice = (_d: unknown, i: number) => i;
const yRotacion = (d: MesRotacion) => d.porcentaje;
const etiquetaMes = (i: number) =>
    rotacion.value[Math.round(i)]?.etiqueta ?? '';
const tooltipRotacion = (d: MesRotacion) =>
    `<div class="tt"><p class="tt-t">${d.etiqueta}</p><p><b>${d.porcentaje}%</b> de rotación</p><p>${d.bajas} ${d.bajas === 1 ? 'baja' : 'bajas'} · plantilla prom. ${d.plantilla_promedio}</p></div>`;
const promedioRotacion = computed(() => {
    const lista = rotacion.value;

    return lista.length
        ? Math.round(
              (lista.reduce((t, m) => t + m.porcentaje, 0) / lista.length) * 10,
          ) / 10
        : 0;
});

// --- Tiempo de contratación por nivel (barras con tooltip por barra) ---
type NivelTiempo = TableroRhDatos['time_to_hire_by_level'][number];
const tiempos = computed(() => props.tablero.time_to_hire_by_level);
const yTiempo = (d: NivelTiempo) => d.dias ?? 0;
const etiquetaNivel = (i: number) =>
    tiempos.value[Math.round(i)]?.etiqueta ?? '';
const tooltipTiempo = {
    [GroupedBar.selectors.bar]: (d: NivelTiempo) =>
        `<div class="tt"><p class="tt-t">${d.etiqueta}</p><p>${d.dias === null ? 'Sin contrataciones' : `<b>${d.dias} días</b> promedio`}</p><p>${d.contratados} contratados · 12 meses</p></div>`,
};
const sinTiempos = computed(() => tiempos.value.every((t) => !t.dias));

// --- Embudo (barras horizontales con conversión entre etapas) ---
const embudo = computed(() => {
    const etapas = props.tablero.recruitment_funnel;
    const max = Math.max(1, ...etapas.map((e) => e.total));

    return etapas.map((e, i) => ({
        ...e,
        ancho: (e.total / max) * 100,
        conversion:
            i === 0 || !etapas[i - 1].total
                ? null
                : Math.round((e.total / etapas[i - 1].total) * 100),
    }));
});

// --- Cobertura por sucursal (ocupada sobre pista de autorizada) ---
const orden = ref<'cobertura' | 'vacantes' | 'nombre'>('cobertura');
const sucursalesCobertura = computed(() => {
    const filas = props.tablero.headcount_by_branch.filter(
        (f) => f.plantilla_autorizada > 0 || f.plantilla_actual > 0,
    );
    const max = Math.max(1, ...filas.map((f) => f.plantilla_autorizada));

    return [...filas]
        .sort((a, b) =>
            orden.value === 'nombre'
                ? a.sucursal.localeCompare(b.sucursal)
                : orden.value === 'vacantes'
                  ? b.vacantes - a.vacantes
                  : a.cumplimiento - b.cumplimiento,
        )
        .map((f) => ({
            ...f,
            pista: (f.plantilla_autorizada / max) * 100,
            ocupada:
                (Math.min(f.plantilla_actual, f.plantilla_autorizada) / max) *
                100,
        }));
});
const totalesCobertura = computed(() => {
    const filas = props.tablero.headcount_by_branch;
    const autorizada = filas.reduce((t, f) => t + f.plantilla_autorizada, 0);
    const ocupada = filas.reduce((t, f) => t + f.plantilla_actual, 0);

    return {
        autorizada,
        ocupada,
        vacantes: filas.reduce((t, f) => t + f.vacantes, 0),
        porcentaje: autorizada
            ? Math.round((ocupada / autorizada) * 1000) / 10
            : 0,
    };
});
</script>

<template>
    <section
        class="flex flex-col gap-4"
        :class="cargando && 'pointer-events-none'"
        aria-label="Tablero de Recursos Humanos"
        :aria-busy="cargando"
    >
        <!-- Encabezado + filtros en una sola fila -->
        <header
            class="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between"
        >
            <div class="min-w-0">
                <h1 class="text-2xl font-semibold tracking-tight">
                    Tablero de Recursos Humanos
                </h1>
                <p class="text-sm text-muted-foreground">
                    {{ tablero.filters.periodo_etiqueta }}
                    <span v-if="sucursal !== TODAS">
                        ·
                        {{
                            tablero.filters.sucursales.find(
                                (x) => String(x.id) === sucursal,
                            )?.nombre
                        }}</span
                    >
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <MonthPicker v-model="mes" class="w-48" />
                <Select
                    v-if="tablero.filters.empresas.length > 1"
                    v-model="empresa"
                >
                    <SelectTrigger class="h-9 w-44" aria-label="Empresa">
                        <SelectValue placeholder="Empresa" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="TODAS"
                            >Todas las empresas</SelectItem
                        >
                        <SelectItem
                            v-for="e in tablero.filters.empresas"
                            :key="e.id"
                            :value="String(e.id)"
                            >{{ e.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <Select
                    v-if="tablero.filters.sucursales.length > 1"
                    v-model="sucursal"
                >
                    <SelectTrigger class="h-9 w-52" aria-label="Sucursal">
                        <SelectValue placeholder="Sucursal" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="TODAS"
                            >Todas las sucursales</SelectItem
                        >
                        <SelectItem
                            v-for="op in sucursalesDeEmpresa"
                            :key="op.id"
                            :value="String(op.id)"
                            >{{ op.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
                <div
                    class="inline-flex h-9 items-center rounded-md border bg-muted/40 p-0.5"
                    role="radiogroup"
                    aria-label="Rango de la tendencia"
                >
                    <button
                        v-for="n in [6, 12, 24]"
                        :key="n"
                        type="button"
                        role="radio"
                        :aria-checked="meses === n"
                        class="h-full rounded-[5px] px-2.5 text-xs font-medium transition-colors"
                        :class="
                            meses === n
                                ? 'bg-background text-foreground shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="meses = n"
                    >
                        {{ n }} m
                    </button>
                </div>
                <Button
                    v-if="hayFiltros"
                    variant="ghost"
                    size="sm"
                    class="h-9"
                    @click="limpiar"
                >
                    <RotateCcw class="size-4" /> Limpiar
                </Button>
            </div>
        </header>

        <div
            class="flex flex-col gap-4 transition-opacity duration-200"
            :class="cargando ? 'opacity-50' : 'opacity-100'"
        >
            <!-- KPIs -->
            <div
                data-tour="dashboard-kpis"
                class="grid grid-cols-2 gap-3 md:grid-cols-4 2xl:grid-cols-8"
            >
                <Card
                    v-for="t in tarjetas"
                    :key="t.clave"
                    class="gap-2 rounded-xl py-4 shadow-xs transition-shadow hover:shadow-md"
                >
                    <CardContent class="flex flex-col gap-1.5 px-4">
                        <div
                            class="flex items-center justify-between gap-2 text-muted-foreground"
                        >
                            <p class="text-xs font-medium">{{ t.titulo }}</p>
                            <component
                                :is="t.icono"
                                class="size-4 shrink-0"
                                aria-hidden="true"
                            />
                        </div>
                        <p
                            class="text-2xl font-semibold tracking-tight tabular-nums"
                        >
                            {{ t.valor }}
                        </p>
                        <div
                            v-if="t.avance !== undefined"
                            class="h-1.5 overflow-hidden rounded-full bg-muted"
                            role="progressbar"
                            :aria-valuenow="t.avance"
                            aria-valuemin="0"
                            aria-valuemax="100"
                            :aria-label="`${t.avance}% de la plantilla autorizada`"
                        >
                            <div
                                class="h-full rounded-full bg-[var(--grafica-plantilla,var(--chart-1))] transition-[width] duration-500"
                                :style="{ width: `${t.avance}%` }"
                            />
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{ t.detalle }}
                        </p>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 xl:grid-cols-3">
                <!-- Rotación mensual -->
                <Card
                    data-tour="dashboard-rotacion"
                    class="gap-3 rounded-xl shadow-xs xl:col-span-2"
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Rotación mensual</CardTitle
                        >
                        <CardDescription>
                            Bajas ÷ plantilla promedio · últimos
                            {{ rotacion.length }} meses · promedio
                            {{ promedioRotacion }}%
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <VisXYContainer
                            :data="rotacion"
                            :height="260"
                            :margin="{ left: 4, right: 12, top: 8, bottom: 4 }"
                        >
                            <VisArea
                                :x="xIndice"
                                :y="yRotacion"
                                color="var(--grafica-rotacion, var(--chart-1))"
                                :opacity="0.12"
                            />
                            <VisLine
                                :x="xIndice"
                                :y="yRotacion"
                                color="var(--grafica-rotacion, var(--chart-1))"
                                :line-width="2"
                            />
                            <VisAxis
                                type="x"
                                :tick-format="etiquetaMes"
                                :num-ticks="Math.min(rotacion.length, 12)"
                                :grid-line="false"
                                :domain-line="false"
                            />
                            <VisAxis
                                type="y"
                                :num-ticks="4"
                                :tick-format="(v: number) => `${v}%`"
                                :domain-line="false"
                            />
                            <VisCrosshair
                                color="var(--grafica-rotacion, var(--chart-1))"
                                :template="tooltipRotacion"
                            />
                            <VisTooltip />
                        </VisXYContainer>
                    </CardContent>
                </Card>

                <!-- Cobertura general -->
                <Card class="gap-3 rounded-xl shadow-xs">
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Cobertura de plantilla</CardTitle
                        >
                        <CardDescription
                            >Plazas autorizadas ocupadas</CardDescription
                        >
                    </CardHeader>
                    <CardContent class="flex flex-col items-center gap-4">
                        <div
                            class="relative grid size-44 place-items-center rounded-full"
                            :style="{
                                background: `conic-gradient(var(--grafica-cobertura, var(--chart-1)) ${totalesCobertura.porcentaje * 3.6}deg, var(--muted) 0deg)`,
                            }"
                            role="img"
                            :aria-label="`${totalesCobertura.porcentaje}% de la plantilla autorizada ocupada`"
                        >
                            <div
                                class="grid size-34 place-items-center rounded-full bg-card text-center"
                            >
                                <div>
                                    <p
                                        class="text-3xl font-semibold tabular-nums"
                                    >
                                        {{ totalesCobertura.porcentaje }}%
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        cubierta
                                    </p>
                                </div>
                            </div>
                        </div>
                        <dl class="grid w-full grid-cols-3 gap-2 text-center">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Autorizada
                                </dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{
                                        numero.format(
                                            totalesCobertura.autorizada,
                                        )
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Ocupada
                                </dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{
                                        numero.format(totalesCobertura.ocupada)
                                    }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    Vacantes
                                </dt>
                                <dd class="text-lg font-semibold tabular-nums">
                                    {{
                                        numero.format(totalesCobertura.vacantes)
                                    }}
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 xl:grid-cols-2">
                <!-- Embudo -->
                <Card
                    data-tour="dashboard-embudo"
                    class="gap-3 rounded-xl shadow-xs"
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Embudo de reclutamiento</CardTitle
                        >
                        <CardDescription
                            >Personas que alcanzaron cada etapa en el periodo ·
                            % de paso desde la etapa anterior</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="e in embudo"
                                :key="e.clave"
                                class="group relative grid grid-cols-[7.5rem_minmax(0,1fr)_3.5rem] items-center gap-3 text-sm"
                            >
                                <span class="truncate">{{ e.etiqueta }}</span>
                                <span
                                    class="h-6 overflow-hidden rounded-md bg-muted/60"
                                >
                                    <span
                                        class="block h-full rounded-md bg-[var(--grafica-embudo,var(--chart-1))] transition-[width,opacity] duration-500 group-hover:opacity-85"
                                        :style="{
                                            width: `${e.ancho}%`,
                                            minWidth: e.total ? '4px' : '0',
                                        }"
                                    />
                                </span>
                                <span class="text-right tabular-nums">
                                    <span class="font-medium">{{
                                        numero.format(e.total)
                                    }}</span>
                                    <span
                                        v-if="e.conversion !== null"
                                        class="block text-[11px] text-muted-foreground"
                                        >{{ e.conversion }}%</span
                                    >
                                </span>
                                <span
                                    class="tt pointer-events-none absolute -top-9 left-32 z-10 hidden group-hover:block"
                                    role="tooltip"
                                >
                                    <b>{{ e.etiqueta }}:</b>
                                    {{ numero.format(e.total) }}
                                    personas<template
                                        v-if="e.conversion !== null"
                                    >
                                        · {{ e.conversion }}% de la etapa
                                        anterior</template
                                    >
                                </span>
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <!-- Tiempo por nivel -->
                <Card
                    data-tour="dashboard-tiempo"
                    class="gap-3 rounded-xl shadow-xs"
                >
                    <CardHeader>
                        <CardTitle class="text-base"
                            >Tiempo de contratación por nivel</CardTitle
                        >
                        <CardDescription
                            >Días promedio de postulación a contratación ·
                            últimos 12 meses</CardDescription
                        >
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="sinTiempos"
                            class="grid h-[240px] place-items-center text-sm text-muted-foreground"
                        >
                            Sin tiempos de contratación medibles en los últimos
                            12 meses.
                        </p>
                        <VisXYContainer
                            v-else
                            :data="tiempos"
                            :height="240"
                            :margin="{ left: 4, right: 4, top: 8, bottom: 4 }"
                        >
                            <VisGroupedBar
                                :x="xIndice"
                                :y="yTiempo"
                                color="var(--grafica-tiempo, var(--chart-1))"
                                :rounded-corners="4"
                                :bar-max-width="56"
                            />
                            <VisAxis
                                type="x"
                                :tick-format="etiquetaNivel"
                                :num-ticks="tiempos.length"
                                :grid-line="false"
                                :domain-line="false"
                            />
                            <VisAxis
                                type="y"
                                :num-ticks="4"
                                :tick-format="(v: number) => `${v} d`"
                                :domain-line="false"
                            />
                            <VisTooltip :triggers="tooltipTiempo" />
                        </VisXYContainer>
                    </CardContent>
                </Card>
            </div>

            <!-- Cobertura por sucursal -->
            <Card class="gap-3 rounded-xl shadow-xs">
                <CardHeader
                    class="flex flex-row flex-wrap items-start justify-between gap-2"
                >
                    <div>
                        <CardTitle class="text-base"
                            >Plantilla por sucursal</CardTitle
                        >
                        <CardDescription
                            >Ocupada sobre autorizada (pista gris) · clic en una
                            sucursal para filtrar el tablero</CardDescription
                        >
                    </div>
                    <div
                        class="inline-flex h-8 items-center rounded-md border bg-muted/40 p-0.5"
                        role="radiogroup"
                        aria-label="Ordenar sucursales"
                    >
                        <button
                            v-for="o in [
                                { v: 'cobertura', t: 'Menor cobertura' },
                                { v: 'vacantes', t: 'Más vacantes' },
                                { v: 'nombre', t: 'Nombre' },
                            ] as const"
                            :key="o.v"
                            type="button"
                            role="radio"
                            :aria-checked="orden === o.v"
                            class="h-full rounded-[5px] px-2.5 text-xs font-medium transition-colors"
                            :class="
                                orden === o.v
                                    ? 'bg-background text-foreground shadow-sm'
                                    : 'text-muted-foreground hover:text-foreground'
                            "
                            @click="orden = o.v"
                        >
                            {{ o.t }}
                        </button>
                    </div>
                </CardHeader>
                <CardContent>
                    <p
                        v-if="sucursalesCobertura.length === 0"
                        class="py-8 text-center text-sm text-muted-foreground"
                    >
                        Sin plantilla autorizada en este filtro.
                    </p>
                    <ul
                        v-else
                        class="grid gap-x-8 gap-y-1 md:grid-cols-2 2xl:grid-cols-3"
                    >
                        <li
                            v-for="f in sucursalesCobertura"
                            :key="f.sucursal_id"
                        >
                            <button
                                type="button"
                                class="group grid w-full grid-cols-[minmax(0,9rem)_minmax(0,1fr)_4.5rem] items-center gap-3 rounded-md px-1.5 py-1.5 text-left text-sm transition-colors hover:bg-muted/60"
                                :title="`${f.sucursal}: ${f.plantilla_actual} de ${f.plantilla_autorizada} plazas ocupadas · ${f.vacantes} vacantes · ${f.cumplimiento}%`"
                                @click="elegirSucursal(f.sucursal_id)"
                            >
                                <span class="truncate">{{ f.sucursal }}</span>
                                <span class="relative h-3">
                                    <span
                                        class="absolute inset-y-0 left-0 rounded-full bg-muted"
                                        :style="{ width: `${f.pista}%` }"
                                    />
                                    <span
                                        class="absolute inset-y-0 left-0 rounded-full bg-[var(--grafica-sucursales,var(--chart-1))] transition-[width] duration-500"
                                        :style="{ width: `${f.ocupada}%` }"
                                    />
                                </span>
                                <span class="text-right tabular-nums">
                                    <span class="font-medium"
                                        >{{ f.plantilla_actual }}/{{
                                            f.plantilla_autorizada
                                        }}</span
                                    >
                                    <span
                                        v-if="f.vacantes > 0"
                                        class="block text-[11px] text-muted-foreground"
                                        >{{ f.vacantes }}
                                        {{
                                            f.vacantes === 1
                                                ? 'vacante'
                                                : 'vacantes'
                                        }}</span
                                    >
                                </span>
                            </button>
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </div>
    </section>
</template>

<style scoped>
/* El contenedor de tooltip de Unovis queda transparente: el diseño lo da .tt */
section {
    --vis-tooltip-padding: 0;
    --vis-tooltip-background-color: transparent;
    --vis-tooltip-border-color: transparent;
    --vis-tooltip-shadow-color: transparent;
    --vis-axis-tick-label-color: var(--muted-foreground);
    --vis-axis-grid-color: color-mix(in oklab, var(--border) 70%, transparent);
    --vis-crosshair-line-stroke-color: var(--muted-foreground);
}

/* Tooltip compartido (Unovis inyecta el HTML; el embudo usa el mismo estilo). */
:deep(.tt) {
    min-width: 9rem;
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    background: var(--popover);
    color: var(--popover-foreground);
    padding: 0.5rem 0.625rem;
    font-size: 0.75rem;
    line-height: 1.35;
    white-space: nowrap;
    box-shadow: 0 4px 16px rgb(0 0 0 / 0.12);
}

:deep(.tt-t) {
    font-weight: 600;
    margin-bottom: 0.125rem;
}
</style>
