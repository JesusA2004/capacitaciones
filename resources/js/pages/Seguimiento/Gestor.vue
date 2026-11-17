<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Banknote,
    FolderOpen,
    Grid3x3,
    HandCoins,
    Link2,
    UserRound,
    Wallet,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvisoReportes from '@/components/Seguimiento/AvisoReportes.vue';
import EsqueletoTablero from '@/components/Seguimiento/EsqueletoTablero.vue';
import SelectorPeriodo from '@/components/Seguimiento/SelectorPeriodo.vue';
import TarjetaKpi from '@/components/Seguimiento/TarjetaKpi.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { usePermisos } from '@/composables/usePermisos';
import { VueApexCharts } from '@/lib/apexDiferido';
import {
    formatoCompacto,
    formatoMoneda,
    formatoPorcentaje,
    tonoMora,
} from '@/lib/seguimiento';
import { dashboard } from '@/routes';
import { show as showExpediente } from '@/routes/rh/expedientes';
import { index as indexSeguimiento } from '@/routes/seguimiento';
import { index as indexGestores, show as showGestor } from '@/routes/seguimiento/gestores';
import { index as indexNineBox } from '@/routes/seguimiento/nine-box';
import { index as indexVinculos } from '@/routes/seguimiento/vinculos';
import type {
    DetalleGestor,
    FiltrosSeguimiento,
    PeriodoSeguimiento,
    RespuestaSeguimiento,
} from '@/types';

const props = defineProps<{
    empleadoId: number;
    filtros: FiltrosSeguimiento;
    configurado: boolean;
    puedeVincular: boolean;
    veTodo: boolean;
    datos: RespuestaSeguimiento<{
        periodo: PeriodoSeguimiento;
        detalle: DetalleGestor | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento', href: indexSeguimiento() },
            { title: 'Por gestor', href: indexGestores() },
            { title: 'Gestor', href: '' },
        ],
    },
});

const { tienePermiso } = usePermisos();
const cargando = ref(false);
const periodo = computed(() => props.datos.datos?.periodo ?? null);
const detalle = computed(() => props.datos.datos?.detalle ?? null);

function cambiar(mes: number): void {
    router.get(
        showGestor.url(props.empleadoId),
        { mes },
        {
            preserveScroll: true,
            replace: true,
            onStart: () => {
                cargando.value = true;
            },
            onFinish: () => {
                cargando.value = false;
            },
        },
    );
}

const serieSemanal = computed(() => [
    { name: 'Colocación', data: detalle.value?.semanal.map((s) => s.colocacion) ?? [] },
    { name: 'Recuperación', data: detalle.value?.semanal.map((s) => s.recuperacion) ?? [] },
]);

const opcionesSemanal = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false } },
    colors: ['var(--chart-1)', 'var(--chart-2)'],
    stroke: { curve: 'smooth' as const, width: 3 },
    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0.02 } },
    dataLabels: { enabled: false },
    markers: { size: 3 },
    legend: { position: 'top' as const, horizontalAlign: 'right' as const },
    grid: { borderColor: 'var(--border)', strokeDashArray: 4 },
    xaxis: {
        categories: detalle.value?.semanal.map((s) => `${s.etiqueta.replace('Semana ', 'S')} ${s.mes.slice(0, 3)}`) ?? [],
    },
    yaxis: { labels: { formatter: (v: number) => formatoCompacto(v) } },
    tooltip: { y: { formatter: (v: number) => formatoMoneda(v) } },
}));

const serieMensual = computed(() => [
    { name: 'Colocación', type: 'column', data: detalle.value?.mensual.map((m) => m.colocacion) ?? [] },
    { name: 'Recuperación', type: 'column', data: detalle.value?.mensual.map((m) => m.recuperacion) ?? [] },
    { name: 'Mora', type: 'line', data: detalle.value?.mensual.map((m) => m.mora) ?? [] },
]);

const opcionesMensual = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit', zoom: { enabled: false } },
    colors: ['var(--chart-1)', 'var(--chart-2)', 'var(--pastel-bronce)'],
    stroke: { width: [0, 0, 3], curve: 'smooth' as const },
    plotOptions: { bar: { borderRadius: 5, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    markers: { size: [0, 0, 4] },
    legend: { position: 'top' as const, horizontalAlign: 'right' as const },
    grid: { borderColor: 'var(--border)', strokeDashArray: 4 },
    xaxis: { categories: detalle.value?.mensual.map((m) => m.etiqueta) ?? [] },
    yaxis: [
        { seriesName: 'Colocación', labels: { formatter: (v: number) => formatoCompacto(v) } },
        { seriesName: 'Colocación', show: false },
        { opposite: true, seriesName: 'Mora', labels: { formatter: (v: number) => `${Math.round(v)} %` } },
    ],
    tooltip: {
        y: {
            formatter: (v: number, opciones?: { seriesIndex: number }) =>
                opciones?.seriesIndex === 2 ? formatoPorcentaje(v) : formatoMoneda(v),
        },
    },
}));
</script>

<template>
    <Head :title="detalle?.gestor.nombre ?? 'Gestor'" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="detalle?.gestor.nombre ?? 'Gestor'"
            :descripcion="[detalle?.gestor.puesto, detalle?.gestor.sucursal ?? detalle?.gestor.branch].filter(Boolean).join(' · ')"
            :icono="UserRound"
            detalle
        >
            <Button v-if="$page.props.features.nine_box && tienePermiso('nine_box.ver')" variant="outline" as-child>
                <Link :href="indexNineBox({ query: periodo?.mes ? { tipo: 'mes', periodo: periodo.mes.codigo } : {} })">
                    <Grid3x3 class="size-4" /> Nine Box
                </Link>
            </Button>
            <Button
                v-if="detalle?.gestor.colaborador_id && tienePermiso('expedientes.ver')"
                variant="outline"
                as-child
            >
                <Link :href="showExpediente(detalle.gestor.colaborador_id)">
                    <FolderOpen class="size-4" /> Expediente
                </Link>
            </Button>
            <Button variant="outline" as-child>
                <Link :href="indexGestores({ query: periodo?.mes ? { mes: periodo.mes.id } : {} })">
                    <ArrowLeft class="size-4" /> Todos los gestores
                </Link>
            </Button>
        </CrudPageHeader>

        <AvisoReportes v-if="datos.error" :mensaje="datos.error" />

        <template v-else-if="periodo">
            <SelectorPeriodo
                :meses="periodo.meses"
                :mes-id="periodo.mes?.id ?? null"
                :semanas="[]"
                :semana-id="null"
                :deshabilitado="cargando"
                @cambiar="(mes: number) => cambiar(mes)"
            />

            <CambioSuave>
                <EsqueletoTablero v-if="cargando" key="cargando" :con-selector="false" />

                <EmptyState
                    v-else-if="!detalle"
                    key="vacio"
                    :icono="UserRound"
                    titulo="Sin datos de este gestor"
                    descripcion="Reportes no tiene información de este gestor en el periodo elegido."
                />

                <div v-else :key="`d-${periodo.mes?.id}`" class="flex flex-col gap-5">
                    <div
                        v-if="!detalle.gestor.vinculado"
                        class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-warning-soft p-3 text-sm"
                    >
                        <p>
                            Este gestor de Reportes
                            <span class="font-medium">({{ detalle.gestor.nombre_reportes }})</span> aún no está
                            vinculado con un colaborador de PEOPLE: sin vínculo no tiene expediente ni potencial en la
                            Nine Box.
                        </p>
                        <Button v-if="puedeVincular" size="sm" variant="outline" class="bg-card" as-child>
                            <Link :href="indexVinculos()"><Link2 class="size-4" /> Vincular</Link>
                        </Button>
                    </div>
                    <div
                        v-if="!detalle.con_actividad"
                        class="rounded-2xl border border-border bg-crema p-3 text-sm"
                    >
                        Sin colocación ni recuperación registradas en {{ periodo.mes?.etiqueta }}.
                    </div>

                    <section class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4">
                        <TarjetaKpi
                            etiqueta="Colocación del mes"
                            :valor="detalle.mes.colocacion"
                            :icono="HandCoins"
                            prefijo="$"
                            tono="salvia"
                            :nota="`${detalle.mes.operaciones ?? 0} créditos · ${periodo.mes?.etiqueta}`"
                        />
                        <TarjetaKpi
                            etiqueta="Recuperación del mes"
                            :valor="detalle.mes.recuperacion"
                            :icono="Banknote"
                            prefijo="$"
                            :nota="periodo.mes?.etiqueta"
                        />
                        <TarjetaKpi
                            etiqueta="Cartera"
                            :valor="detalle.mes.cartera"
                            :icono="Wallet"
                            prefijo="$"
                            tono="crema"
                            nota="Al cierre del mes"
                        />
                        <TarjetaKpi
                            etiqueta="Mora"
                            :valor="detalle.mes.mora"
                            :icono="AlertTriangle"
                            sufijo=" %"
                            :decimales="1"
                            :nota="`Vencida ${formatoMoneda(detalle.mes.vencida)}`"
                        />
                    </section>

                    <section class="grid gap-3 xl:grid-cols-2">
                        <div class="rounded-2xl border border-border bg-card p-4">
                            <div class="mb-1 flex items-baseline justify-between gap-2">
                                <h2 class="text-sm font-semibold">Tendencia semanal</h2>
                                <p class="text-xs text-muted-foreground">Últimas {{ detalle.semanal.length }} semanas</p>
                            </div>
                            <VueApexCharts type="area" height="300" :options="opcionesSemanal" :series="serieSemanal" />
                        </div>
                        <div class="rounded-2xl border border-border bg-card p-4">
                            <div class="mb-1 flex items-baseline justify-between gap-2">
                                <h2 class="text-sm font-semibold">Tendencia mensual</h2>
                                <p class="text-xs text-muted-foreground">KPIs oficiales de la radiografía</p>
                            </div>
                            <VueApexCharts type="line" height="300" :options="opcionesMensual" :series="serieMensual" />
                        </div>
                    </section>

                    <section class="rounded-2xl border border-border bg-card p-4">
                        <h2 class="mb-3 text-sm font-semibold">Mes por mes</h2>
                        <div class="tabla-scroll">
                            <table class="w-full min-w-[640px] text-left text-sm">
                                <thead class="text-xs text-muted-foreground">
                                    <tr class="border-b border-border">
                                        <th class="py-2 pr-3 font-medium">Mes</th>
                                        <th class="py-2 pr-3 text-right font-medium">Colocación</th>
                                        <th class="py-2 pr-3 text-right font-medium">Recuperación</th>
                                        <th class="py-2 pr-3 text-right font-medium">Cartera</th>
                                        <th class="py-2 text-right font-medium">Mora</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="m in [...detalle.mensual].reverse()"
                                        :key="m.mes_id ?? m.etiqueta"
                                        class="border-b border-border/60 last:border-0"
                                    >
                                        <td class="py-2.5 pr-3 font-medium">
                                            {{ m.etiqueta }}
                                            <span v-if="m.estado !== 'ok'" class="ml-1 text-[11px] text-muted-foreground"
                                                >(sin actividad)</span
                                            >
                                        </td>
                                        <td class="py-2.5 pr-3 text-right tabular-nums">{{ formatoMoneda(m.colocacion) }}</td>
                                        <td class="py-2.5 pr-3 text-right tabular-nums">{{ formatoMoneda(m.recuperacion) }}</td>
                                        <td class="py-2.5 pr-3 text-right tabular-nums">{{ formatoMoneda(m.cartera) }}</td>
                                        <td class="py-2.5 text-right">
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium tabular-nums"
                                                :class="tonoMora(m.mora)"
                                                >{{ formatoPorcentaje(m.mora) }}</span
                                            >
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </CambioSuave>
        </template>
    </div>
</template>
