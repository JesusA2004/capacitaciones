<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Banknote,
    Building2,
    HandCoins,
    Link2,
    PieChart,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AvisoReportes from '@/components/Seguimiento/AvisoReportes.vue';
import BarrasSemanas from '@/components/Seguimiento/BarrasSemanas.vue';
import EsqueletoTablero from '@/components/Seguimiento/EsqueletoTablero.vue';
import SelectorPeriodo from '@/components/Seguimiento/SelectorPeriodo.vue';
import TarjetaKpi from '@/components/Seguimiento/TarjetaKpi.vue';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { useSeguimientoPeriodo } from '@/composables/useSeguimientoPeriodo';
import { VueApexCharts } from '@/lib/apexDiferido';
import {
    formatoCompacto,
    formatoMoneda,
    formatoPorcentaje,
    tonoMora,
} from '@/lib/seguimiento';
import { dashboard } from '@/routes';
import { index as indexGestores } from '@/routes/seguimiento/gestores';
import { index as indexSeguimientoSucursales } from '@/routes/seguimiento/sucursales';
import { index as indexVinculos } from '@/routes/seguimiento/vinculos';
import type {
    FiltrosSeguimiento,
    PeriodoSeguimiento,
    RespuestaSeguimiento,
    TableroSucursales,
} from '@/types';

const props = defineProps<{
    filtros: FiltrosSeguimiento;
    configurado: boolean;
    puedeVincular: boolean;
    veTodo: boolean;
    datos?: RespuestaSeguimiento<{
        periodo: PeriodoSeguimiento;
        tablero: TableroSucursales | null;
    }>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Seguimiento', href: '' },
        ],
    },
});

const { cargando, ir } = useSeguimientoPeriodo(() => indexSeguimientoSucursales.url());

const periodo = computed(() => props.datos?.datos?.periodo ?? null);
const tablero = computed(() => props.datos?.datos?.tablero ?? null);
const semanaElegida = computed(() =>
    tablero.value?.semanas.find((s) => s.id === tablero.value?.semana_id) ?? null,
);
const indiceSemana = computed(() =>
    semanaElegida.value === null
        ? null
        : (tablero.value?.semanas.findIndex((s) => s.id === semanaElegida.value?.id) ?? null),
);
const alcanceTexto = computed(() =>
    semanaElegida.value
        ? `${semanaElegida.value.etiqueta} de ${periodo.value?.mes?.etiqueta ?? ''}`
        : (periodo.value?.mes?.etiqueta ?? ''),
);

function cambiar(mes: number, semana: number | null): void {
    ir({ mes, semana });
}

const serieSemanas = computed(() => [
    {
        name: 'Colocación',
        data: tablero.value?.serie_semanal.map((s) => s.colocacion) ?? [],
    },
    {
        name: 'Recuperación',
        data: tablero.value?.serie_semanal.map((s) => s.recuperacion) ?? [],
    },
]);

const opcionesSemanas = computed(() => ({
    chart: {
        toolbar: { show: false },
        fontFamily: 'inherit',
        zoom: { enabled: false },
        animations: { enabled: true, speed: 450 },
    },
    colors: ['var(--chart-1)', 'var(--chart-2)'],
    plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
    dataLabels: { enabled: false },
    legend: { position: 'top' as const, horizontalAlign: 'right' as const },
    grid: { borderColor: 'var(--border)', strokeDashArray: 4 },
    xaxis: {
        categories: tablero.value?.serie_semanal.map((s) => s.etiqueta) ?? [],
    },
    yaxis: { labels: { formatter: (v: number) => formatoCompacto(v) } },
    tooltip: { y: { formatter: (v: number) => formatoMoneda(v) } },
}));

const rankingSucursales = computed(() =>
    [...(tablero.value?.sucursales ?? [])]
        .filter((s) => s.colocacion !== null)
        .sort((a, b) => (b.colocacion ?? 0) - (a.colocacion ?? 0))
        .slice(0, 8),
);

const opcionesRanking = computed(() => ({
    chart: { toolbar: { show: false }, fontFamily: 'inherit' },
    colors: ['var(--chart-3)'],
    // La etiqueta va FUERA de la barra (al final): dentro se cortaba en las
    // sucursales con poca colocación («$894.7» recortado).
    plotOptions: {
        bar: {
            horizontal: true,
            borderRadius: 5,
            barHeight: '60%',
            dataLabels: { position: 'top' },
        },
    },
    dataLabels: {
        enabled: true,
        formatter: (v: number) => formatoCompacto(v),
        offsetX: 36,
        style: { fontSize: '11px', colors: ['var(--foreground)'] },
    },
    grid: {
        borderColor: 'var(--border)',
        strokeDashArray: 4,
        padding: { right: 36 },
    },
    xaxis: {
        categories: rankingSucursales.value.map((s) => s.nombre),
        labels: { formatter: (v: string) => formatoCompacto(Number(v)) },
    },
    tooltip: { y: { formatter: (v: number) => formatoMoneda(v) } },
}));
</script>

<template>
    <Head title="Seguimiento por sucursal" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Seguimiento por sucursal"
            descripcion="Colocación, recuperación, cartera y mora de Reportes, por semana y por mes."
            :icono="Building2"
        >
            <Button variant="outline" as-child>
                <Link :href="indexGestores({ query: filtros.mes ? { mes: filtros.mes } : {} })">
                    <Users class="size-4" /> Por gestor
                </Link>
            </Button>
            <Button v-if="puedeVincular" variant="outline" as-child>
                <Link :href="indexVinculos()">
                    <Link2 class="size-4" /> Vínculos con Reportes
                </Link>
            </Button>
        </CrudPageHeader>

        <Deferred data="datos">
            <template #fallback>
                <EsqueletoTablero />
            </template>

            <AvisoReportes v-if="datos?.error" :mensaje="datos.error" />

            <template v-else-if="periodo">
                <SelectorPeriodo
                    :meses="periodo.meses"
                    :mes-id="periodo.mes?.id ?? null"
                    :semanas="tablero?.semanas ?? []"
                    :semana-id="tablero?.semana_id ?? null"
                    :deshabilitado="cargando"
                    @cambiar="cambiar"
                />

                <CambioSuave>
                    <EsqueletoTablero v-if="cargando" key="cargando" :con-selector="false" />

                    <EmptyState
                        v-else-if="!tablero || tablero.estado === 'sin_datos'"
                        key="sin-datos"
                        :icono="PieChart"
                        titulo="Reportes aún no tiene datos de este mes"
                        :descripcion="tablero?.mensaje ?? 'Cuando Reportes genere la radiografía del mes, aquí aparecerán sus datos.'"
                    />

                    <EmptyState
                        v-else-if="tablero.sucursales.length === 0"
                        key="vacio"
                        :icono="Building2"
                        titulo="Sin sucursales para mostrar"
                        :descripcion="
                            puedeVincular
                                ? 'Ninguna sucursal de tu alcance está vinculada con Reportes todavía. Vincúlalas en «Vínculos con Reportes».'
                                : 'Ninguna sucursal de tu alcance tiene datos de Reportes en este periodo.'
                        "
                    />

                    <div v-else :key="`t-${periodo.mes?.id}-${tablero.semana_id}`" class="flex flex-col gap-5">
                        <section
                            class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-5"
                            :aria-label="`Indicadores de ${alcanceTexto}`"
                        >
                            <TarjetaKpi
                                etiqueta="Colocación"
                                :valor="tablero.totales.colocacion"
                                :icono="HandCoins"
                                prefijo="$"
                                tono="salvia"
                                :nota="`${(tablero.totales.operaciones ?? 0).toLocaleString('es-MX')} créditos · ${alcanceTexto}`"
                            />
                            <TarjetaKpi
                                etiqueta="Recuperación"
                                :valor="tablero.totales.recuperacion"
                                :icono="Banknote"
                                prefijo="$"
                                :nota="alcanceTexto"
                            />
                            <TarjetaKpi
                                etiqueta="Cartera total"
                                :valor="tablero.totales.cartera"
                                :icono="Wallet"
                                prefijo="$"
                                tono="crema"
                                nota="Al cierre del mes"
                            />
                            <TarjetaKpi
                                etiqueta="Mora"
                                :valor="tablero.totales.mora"
                                :icono="AlertTriangle"
                                sufijo=" %"
                                :decimales="1"
                                :nota="`Vencida ${formatoMoneda(tablero.totales.vencida)} · cierre del mes`"
                            />
                            <TarjetaKpi
                                class="col-span-2 lg:col-span-1"
                                etiqueta="Gestores con actividad"
                                :valor="tablero.totales.gestores"
                                :icono="Users"
                                tono="menta"
                                :nota="`${tablero.totales.sucursales} ${tablero.totales.sucursales === 1 ? 'sucursal' : 'sucursales'}`"
                            />
                        </section>

                        <section class="grid gap-3 lg:grid-cols-3">
                            <div class="rounded-2xl border border-border bg-card p-4 lg:col-span-2">
                                <div class="mb-1 flex flex-wrap items-baseline justify-between gap-2">
                                    <h2 class="text-sm font-semibold">Semanas del mes</h2>
                                    <p class="text-xs text-muted-foreground">
                                        Por fecha de operación y de pago · {{ periodo.mes?.etiqueta }}
                                    </p>
                                </div>
                                <VueApexCharts
                                    type="bar"
                                    height="300"
                                    :options="opcionesSemanas"
                                    :series="serieSemanas"
                                />
                            </div>
                            <div class="rounded-2xl border border-border bg-card p-4">
                                <div class="mb-1 flex items-baseline justify-between gap-2">
                                    <h2 class="text-sm font-semibold">Colocación por sucursal</h2>
                                    <p class="text-xs text-muted-foreground">{{ alcanceTexto }}</p>
                                </div>
                                <VueApexCharts
                                    type="bar"
                                    height="300"
                                    :options="opcionesRanking"
                                    :series="[{ name: 'Colocación', data: rankingSucursales.map((s) => s.colocacion ?? 0) }]"
                                />
                            </div>
                        </section>

                        <section class="rounded-2xl border border-border bg-card p-4">
                            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                                <h2 class="text-sm font-semibold">Sucursales</h2>
                                <p class="text-xs text-muted-foreground">
                                    Cartera y mora son un corte de MES (no existen por semana).
                                </p>
                            </div>
                            <div class="tabla-scroll">
                                <table class="w-full min-w-[760px] text-left text-sm">
                                    <thead class="text-xs text-muted-foreground">
                                        <tr class="border-b border-border">
                                            <th class="py-2 pr-3 font-medium">Sucursal</th>
                                            <th class="py-2 pr-3 text-right font-medium">Colocación</th>
                                            <th class="py-2 pr-3 text-right font-medium">Recuperación</th>
                                            <th class="py-2 pr-3 text-right font-medium">Cartera</th>
                                            <th class="py-2 pr-3 text-right font-medium">Mora</th>
                                            <th class="py-2 pr-3 text-right font-medium">Gestores</th>
                                            <th class="py-2 pr-3 font-medium">Semanas</th>
                                            <th class="py-2" />
                                        </tr>
                                    </thead>
                                    <ListaAnimada tag="tbody" :cantidad="tablero.sucursales.length">
                                        <tr
                                            v-for="fila in tablero.sucursales"
                                            :key="fila.id"
                                            class="border-b border-border/60 transicion-ui last:border-0 hover:bg-crema/40"
                                        >
                                            <td class="py-2.5 pr-3">
                                                <p class="font-medium">{{ fila.nombre }}</p>
                                                <p class="text-[11px] text-muted-foreground">
                                                    {{ fila.branches.join(' · ') }}
                                                </p>
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ formatoMoneda(fila.colocacion) }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ formatoMoneda(fila.recuperacion) }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ formatoMoneda(fila.cartera) }}
                                            </td>
                                            <td class="py-2.5 pr-3 text-right">
                                                <span
                                                    class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium tabular-nums"
                                                    :class="tonoMora(fila.mora)"
                                                    >{{ formatoPorcentaje(fila.mora) }}</span
                                                >
                                            </td>
                                            <td class="py-2.5 pr-3 text-right tabular-nums">
                                                {{ fila.gestores }}
                                            </td>
                                            <td class="py-2.5 pr-3">
                                                <BarrasSemanas
                                                    :valores="fila.semanas.map((s) => s.colocacion)"
                                                    :etiquetas="tablero.semanas.map((s) => s.etiqueta)"
                                                    :resaltada="indiceSemana"
                                                />
                                            </td>
                                            <td class="py-2.5 text-right">
                                                <Button variant="ghost" size="sm" as-child>
                                                    <Link
                                                        :href="
                                                            indexGestores({
                                                                query: {
                                                                    mes: periodo.mes?.id ?? null,
                                                                    sucursal: fila.id,
                                                                },
                                                            })
                                                        "
                                                        >Gestores</Link
                                                    >
                                                </Button>
                                            </td>
                                        </tr>
                                    </ListaAnimada>
                                </table>
                            </div>
                        </section>

                        <section
                            v-if="tablero.sin_vincular"
                            class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-warning-soft p-4 text-sm"
                        >
                            <p>
                                <span class="font-semibold">{{ tablero.sin_vincular.branches }}
                                    {{ tablero.sin_vincular.branches === 1 ? 'sucursal' : 'sucursales' }} de Reportes sin
                                    vincular</span>
                                ({{ tablero.sin_vincular.nombres.join(', ') }}) con
                                {{ formatoMoneda(tablero.sin_vincular.colocacion) }} de colocación en el mes: no
                                están sumadas arriba.
                            </p>
                            <Button v-if="puedeVincular" size="sm" as-child>
                                <Link :href="indexVinculos()">Vincular</Link>
                            </Button>
                        </section>

                        <p class="text-xs text-muted-foreground">
                            Fuente: Reportes (radiografía del mes y actividad semanal por fecha de operación).
                            <span v-if="tablero.calculado_en">Calculado: {{ tablero.calculado_en }}.</span>
                        </p>
                    </div>
                </CambioSuave>
            </template>
        </Deferred>
    </div>
</template>
