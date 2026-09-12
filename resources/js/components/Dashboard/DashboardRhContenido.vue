<script setup lang="ts">
import {
    Briefcase,
    CakeSlice,
    FileCheck2,
    FileClock,
    FileWarning,
    Info,
    MapPin,
    MapPinOff,
    Sparkles,
    UserMinus,
    UserPlus,
    Users,
    Users2,
    Wand2,
} from '@lucide/vue';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import MetricCard from '@/components/Common/MetricCard.vue';
import DashboardSection from '@/components/Dashboard/DashboardSection.vue';
import RotacionPersonal from '@/components/Dashboard/RotacionPersonal.vue';
import type { DashboardRhProps, PuntoConteo, PuntoConteoClave } from '@/types';

// rotacion/sucursalesFiltro/departamentosFiltro son opcionales: este
// componente también lo usa Reportes/Index.vue (hub unificado), que hoy no
// trae esos datos. Cuando falten, la sección de rotación simplemente no se
// muestra ahí.
const props = defineProps<
    Omit<DashboardRhProps, 'rotacion' | 'sucursalesFiltro' | 'departamentosFiltro'> &
        Partial<Pick<DashboardRhProps, 'rotacion' | 'sucursalesFiltro' | 'departamentosFiltro'>>
>();

const TONO_ALERTA: Record<string, string> = {
    warning: 'border-warning/30 bg-warning/10 text-warning',
    danger: 'border-destructive/30 bg-destructive/10 text-destructive',
    info: 'border-[var(--brand-secondary)]/30 bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]',
};

const tarjetasKpi = computed(() => [
    { etiqueta: 'Colaboradores activos', valor: props.cards.colaboradores_activos, icono: Users2, colorClase: 'bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]' },
    { etiqueta: 'Altas en proceso', valor: props.cards.altas_en_proceso, icono: UserPlus, colorClase: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
    { etiqueta: 'Bajas del mes', valor: props.cards.bajas_del_mes, icono: UserMinus, colorClase: 'bg-destructive/10 text-destructive' },
    { etiqueta: 'Solicitudes pendientes', valor: props.cards.solicitudes_pendientes, icono: FileClock, colorClase: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
    { etiqueta: 'Vacaciones pendientes', valor: props.cards.vacaciones_pendientes, icono: FileClock, colorClase: 'bg-amber-500/10 text-amber-600 dark:text-amber-400' },
    { etiqueta: 'Expedientes completos', valor: props.cards.expedientes_completos, icono: FileCheck2, colorClase: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    { etiqueta: 'Expedientes incompletos', valor: props.cards.expedientes_incompletos, icono: FileWarning, colorClase: 'bg-warning/10 text-warning' },
    { etiqueta: 'Documentos pendientes', valor: props.cards.documentos_pendientes, icono: FileClock, colorClase: 'bg-warning/10 text-warning' },
    { etiqueta: 'Vacantes disponibles', valor: props.cards.vacantes_disponibles, icono: Briefcase, colorClase: 'bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]' },
    { etiqueta: 'Plazas automáticas', valor: props.cards.plazas_automaticas, icono: Wand2, colorClase: 'bg-sky-500/10 text-sky-600 dark:text-sky-400' },
    { etiqueta: 'Candidatos activos', valor: props.cards.candidatos_activos, icono: Users, colorClase: 'bg-indigo-500/10 text-indigo-600 dark:text-indigo-400' },
    { etiqueta: 'Rutas cubiertas', valor: props.cards.rutas_cubiertas, icono: MapPin, colorClase: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400' },
    { etiqueta: 'Rutas sin cubrir', valor: props.cards.rutas_sin_cubrir, icono: MapPinOff, colorClase: 'bg-destructive/10 text-destructive' },
    { etiqueta: 'Cumpleaños (7 días)', valor: props.cards.cumpleanos_proximos, icono: CakeSlice, colorClase: 'bg-pink-500/10 text-pink-600 dark:text-pink-400' },
]);

const paletaMarca = [
    'var(--brand-primary)',
    'var(--brand-secondary)',
    '#f59e0b',
    '#10b981',
    '#6366f1',
    '#ec4899',
];

const opcionesBase = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit' },
    colors: paletaMarca,
    dataLabels: { enabled: false },
    legend: { position: 'bottom' as const },
    grid: { borderColor: 'var(--border)' },
}));

function barrasHorizontales(categorias: string[]) {
    return {
        ...opcionesBase.value,
        chart: { ...opcionesBase.value.chart, type: 'bar' as const },
        plotOptions: { bar: { horizontal: true, borderRadius: 4 } },
        xaxis: { categories: categorias },
    };
}

function donaEtiquetas(puntos: PuntoConteoClave[] | PuntoConteo[]) {
    return {
        ...opcionesBase.value,
        chart: { ...opcionesBase.value.chart, type: 'donut' as const },
        labels: puntos.map((p) => p.etiqueta),
    };
}

const vacantesPorPuestoSeries = computed(() => [
    { name: 'Vacantes', data: props.graficas.vacantesPorPuesto.slice(0, 8).map((p) => p.valor) },
]);
const vacantesPorPuestoOpciones = computed(() =>
    barrasHorizontales(props.graficas.vacantesPorPuesto.slice(0, 8).map((p) => p.etiqueta)),
);

const candidatosPorEtapaSeries = computed(() => [
    { name: 'Candidatos', data: props.graficas.candidatosPorEtapa.map((p) => p.valor) },
]);
const candidatosPorEtapaOpciones = computed(() =>
    barrasHorizontales(props.graficas.candidatosPorEtapa.map((p) => p.etiqueta)),
);

const coberturaRutasSeries = computed(() => props.graficas.coberturaRutas.map((p) => p.valor));
const coberturaRutasOpciones = computed(() => donaEtiquetas(props.graficas.coberturaRutas));

const solicitudesPorEstadoSeries = computed(() => props.graficas.solicitudesPorEstado.map((p) => p.valor));
const solicitudesPorEstadoOpciones = computed(() => donaEtiquetas(props.graficas.solicitudesPorEstado));

const expedientesEstadoSeries = computed(() => props.graficas.expedientesEstado.map((p) => p.valor));
const expedientesEstadoOpciones = computed(() => donaEtiquetas(props.graficas.expedientesEstado));

const documentosPorEstadoSeries = computed(() => props.graficas.documentosPorEstado.map((p) => p.valor));
const documentosPorEstadoOpciones = computed(() => donaEtiquetas(props.graficas.documentosPorEstado));

const colaboradoresPorSucursalSeries = computed(() => [
    { name: 'Colaboradores', data: props.graficas.colaboradoresPorSucursal.slice(0, 8).map((p) => p.valor) },
]);
const colaboradoresPorSucursalOpciones = computed(() =>
    barrasHorizontales(props.graficas.colaboradoresPorSucursal.slice(0, 8).map((p) => p.etiqueta)),
);
</script>

<template>
    <div class="flex flex-col gap-8">
        <div
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7"
        >
            <MetricCard
                v-for="tarjeta in tarjetasKpi"
                :key="tarjeta.etiqueta"
                :etiqueta="tarjeta.etiqueta"
                :valor="tarjeta.valor"
                :icono="tarjeta.icono"
                :color-clase="tarjeta.colorClase"
            />
        </div>

        <DashboardSection
            titulo="Cobertura y reclutamiento"
            descripcion="Vacantes por puesto, candidatos por etapa, cobertura de rutas y solicitudes por estado."
            :columnas="2"
        >
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="mb-2 text-sm font-semibold">Vacantes por puesto</p>
                <p
                    v-if="!graficas.vacantesPorPuesto.length"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin vacantes abiertas.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="220"
                    :options="vacantesPorPuestoOpciones"
                    :series="vacantesPorPuestoSeries"
                />
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="mb-2 text-sm font-semibold">Candidatos por etapa</p>
                <p
                    v-if="!graficas.candidatosPorEtapa.length"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin candidatos activos.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="220"
                    :options="candidatosPorEtapaOpciones"
                    :series="candidatosPorEtapaSeries"
                />
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="mb-2 text-sm font-semibold">Cobertura de rutas</p>
                <p
                    v-if="!graficas.coberturaRutas.some((p) => p.valor > 0)"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin rutas activas registradas.
                </p>
                <VueApexCharts
                    v-else
                    type="donut"
                    height="220"
                    :options="coberturaRutasOpciones"
                    :series="coberturaRutasSeries"
                />
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="mb-2 text-sm font-semibold">
                    Solicitudes por estado
                </p>
                <p
                    v-if="!graficas.solicitudesPorEstado.some((p) => p.valor > 0)"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin solicitudes registradas.
                </p>
                <VueApexCharts
                    v-else
                    type="donut"
                    height="220"
                    :options="solicitudesPorEstadoOpciones"
                    :series="solicitudesPorEstadoSeries"
                />
            </div>
        </DashboardSection>

        <DashboardSection
            titulo="Plantilla y cumplimiento documental"
            descripcion="Dónde está la plantilla activa y qué tan al día están los expedientes."
            :columnas="3"
        >
            <div class="rounded-2xl border border-border/60 bg-card p-4 sm:col-span-2 xl:col-span-1">
                <p class="mb-2 text-sm font-semibold">
                    Colaboradores por sucursal
                </p>
                <p
                    v-if="!graficas.colaboradoresPorSucursal.length"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin colaboradores que mostrar.
                </p>
                <VueApexCharts
                    v-else
                    type="bar"
                    height="220"
                    :options="colaboradoresPorSucursalOpciones"
                    :series="colaboradoresPorSucursalSeries"
                />
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="mb-2 text-sm font-semibold">Expedientes</p>
                <p
                    v-if="!graficas.expedientesEstado.some((p) => p.valor > 0)"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin expedientes que mostrar.
                </p>
                <VueApexCharts
                    v-else
                    type="donut"
                    height="220"
                    :options="expedientesEstadoOpciones"
                    :series="expedientesEstadoSeries"
                />
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="mb-2 text-sm font-semibold">
                    Documentos por estado
                </p>
                <p
                    v-if="!graficas.documentosPorEstado.some((p) => p.valor > 0)"
                    class="flex h-[220px] items-center justify-center text-center text-sm text-muted-foreground"
                >
                    Sin documentos que mostrar.
                </p>
                <VueApexCharts
                    v-else
                    type="donut"
                    height="220"
                    :options="documentosPorEstadoOpciones"
                    :series="documentosPorEstadoSeries"
                />
            </div>
        </DashboardSection>

        <DashboardSection
            v-if="rotacion"
            titulo="Rotación de personal"
            descripcion="Altas, bajas, plantilla y cumplimiento en tiempo real — filtra por sucursal, departamento y periodo."
            :columnas="1"
        >
            <RotacionPersonal
                :datos-iniciales="rotacion"
                :sucursales="sucursalesFiltro ?? []"
                :departamentos="departamentosFiltro ?? []"
            />
        </DashboardSection>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="rounded-2xl border border-border/60 bg-card p-5 lg:col-span-2">
                <div class="mb-3 flex items-center gap-2">
                    <CakeSlice class="size-4 text-[var(--brand-primary)]" />
                    <h3 class="text-sm font-semibold">
                        Próximos aniversarios laborales
                    </h3>
                </div>

                <p
                    v-if="!proximosAniversarios.length"
                    class="rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
                >
                    Sin aniversarios en los próximos 30 días.
                </p>

                <div v-else class="flex flex-col divide-y divide-border/60">
                    <div
                        v-for="item in proximosAniversarios"
                        :key="item.id"
                        class="flex items-center justify-between gap-3 rounded-xl px-2 py-3 transition-colors hover:bg-muted/40"
                    >
                        <div class="flex items-center gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-[var(--brand-primary)]/10 text-sm font-semibold text-[var(--brand-primary)]"
                            >
                                {{ item.anios }}
                            </span>
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium">
                                    {{ item.nombre }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{ item.anios }}
                                    {{ item.anios === 1 ? 'año' : 'años' }} en
                                    la empresa
                                </p>
                            </div>
                        </div>
                        <span
                            class="shrink-0 rounded-full px-3 py-1 text-xs font-medium"
                            :class="
                                item.dias <= 7
                                    ? 'bg-success/10 text-success'
                                    : 'bg-muted text-muted-foreground'
                            "
                        >
                            {{ item.dias === 0 ? 'Hoy' : `en ${item.dias}d` }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="rounded-2xl border border-border/60 bg-card p-5">
                <div class="mb-3 flex items-center gap-2">
                    <Info class="size-4 text-[var(--brand-secondary)]" />
                    <h3 class="text-sm font-semibold">Alertas RH</h3>
                </div>
                <p
                    v-if="!alertas.length"
                    class="flex items-center gap-2 rounded-xl border border-dashed border-border/60 p-6 text-center text-sm text-muted-foreground"
                >
                    <Sparkles class="size-4 shrink-0" />
                    Sin alertas por ahora, todo en orden.
                </p>
                <div v-else class="flex flex-col gap-2">
                    <div
                        v-for="(alerta, indice) in alertas"
                        :key="indice"
                        class="rounded-xl border px-3 py-2 text-xs transition-colors"
                        :class="TONO_ALERTA[alerta.tono]"
                    >
                        {{ alerta.mensaje }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
