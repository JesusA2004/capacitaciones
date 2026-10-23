<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BarChart3, Download } from '@lucide/vue';
import { computed } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Button } from '@/components/ui/button';
import { useFiltros } from '@/composables/useFiltros';
import { usePaginacionLocal } from '@/composables/usePaginacion';
import { VueApexCharts } from '@/lib/apexDiferido';
import { opcionesDe } from '@/lib/opcionesSelect';
import { dashboard } from '@/routes';
import { excel, index, pdf } from '@/routes/rh/reportes';
import type {
    FiltrosReporteRh,
    GraficaReporte,
    GrupoCatalogoReportes,
    OpcionesReportesRh,
    ResultadoReporteRh,
} from '@/types';

const props = defineProps<{
    catalogo: GrupoCatalogoReportes[];
    reporte: string;
    filtros: FiltrosReporteRh;
    resultado: ResultadoReporteRh;
    grafica: GraficaReporte | null;
    puedeExportar: boolean;
    opciones: OpcionesReportesRh;
}>();

// El resultado llega completo: la tabla se pagina en el navegador.
const paginacion = usePaginacionLocal(() => props.resultado.filas, {
    clave: 'rh-reportes-resultado',
});

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reportes RH', href: index.url() },
        ],
    },
});

const { filtros, aplicar, limpiar } = useFiltros(index.url(), {
    reporte: props.reporte,
    empresa_id: props.filtros.empresa_id ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    puesto_id: props.filtros.puesto_id ?? '',
});

function urlExportar(destino: typeof excel | typeof pdf): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

const paletaMarca = [
    'var(--brand-primary)',
    'var(--brand-secondary)',
    '#f59e0b',
    '#10b981',
    '#6366f1',
    '#ec4899',
];

// Con pocas columnas la tabla cabe junto a la gráfica en pantallas anchas;
// con muchas, cada una ocupa todo el ancho para no apretar la tabla.
const ladoALado = computed(
    () =>
        opcionesGrafica.value !== null && props.resultado.columnas.length <= 4,
);

const opcionesGrafica = computed(() => {
    const g = props.grafica;

    if (!g) {
        return null;
    }

    const base = {
        chart: { toolbar: { show: false }, fontFamily: 'inherit' },
        colors: paletaMarca,
        dataLabels: { enabled: false },
        legend: { position: 'bottom' as const },
        grid: { borderColor: 'var(--border)' },
    };

    if (g.tipo === 'distribucion') {
        return {
            tipoChart: 'donut' as const,
            options: { ...base, labels: g.categorias },
            series: g.series[0]?.valores ?? [],
        };
    }

    return {
        tipoChart: 'bar' as const,
        options: {
            ...base,
            chart: { ...base.chart, type: 'bar' as const },
            xaxis: { categories: g.categorias },
            plotOptions: {
                bar: {
                    borderRadius: 4,
                    columnWidth: g.series.length > 1 ? '55%' : '45%',
                },
            },
        },
        series: g.series.map((s) => ({ name: s.nombre, data: s.valores })),
    };
});
</script>

<template>
    <Head :title="resultado.titulo" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Reportes RH"
            descripcion="Consulta y exporta la información de RH filtrada por empresa, sucursal, departamento o puesto."
            :icono="BarChart3"
        >
            <template v-if="puedeExportar">
                <CrudExportButtons
                    :url-excel="urlExportar(excel)"
                    :url-pdf="urlExportar(pdf)"
                />
            </template>
        </CrudPageHeader>

        <div
            data-tour="reportes-filtros"
            class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-[minmax(0,2fr)_repeat(4,minmax(0,1fr))_auto]"
        >
            <div
                data-tour="reportes-tipo"
                class="grid min-w-0 gap-2 sm:col-span-2 lg:col-span-1"
            >
                <label class="text-xs text-muted-foreground">Reporte</label>
                <SelectSimple
                    :model-value="filtros.reporte"
                    :opciones="
                        catalogo.flatMap((g) =>
                            g.opciones.map((o) => ({
                                value: o.value,
                                label: o.label,
                                grupo: g.grupo,
                            })),
                        )
                    "
                    class="w-full"
                    @update:model-value="
                        (v) => {
                            filtros.reporte = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <div class="grid min-w-0 gap-2">
                <label class="text-xs text-muted-foreground">Empresa</label>
                <SelectSimple
                    :model-value="filtros.empresa_id"
                    :opciones="opcionesDe(opciones.empresas)"
                    placeholder="Todas"
                    opcion-vacia="Todas las empresas"
                    class="w-full"
                    @update:model-value="
                        (v) => {
                            filtros.empresa_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <div class="grid min-w-0 gap-2">
                <label class="text-xs text-muted-foreground">Sucursal</label>
                <SelectSimple
                    :model-value="filtros.sucursal_id"
                    :opciones="opcionesDe(opciones.sucursales)"
                    placeholder="Todas"
                    opcion-vacia="Todas las sucursales"
                    class="w-full"
                    @update:model-value="
                        (v) => {
                            filtros.sucursal_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <div class="grid min-w-0 gap-2">
                <label class="text-xs text-muted-foreground"
                    >Departamento</label
                >
                <SelectSimple
                    :model-value="filtros.departamento_id"
                    :opciones="opcionesDe(opciones.departamentos)"
                    placeholder="Todos"
                    opcion-vacia="Todos los departamentos"
                    class="w-full"
                    @update:model-value="
                        (v) => {
                            filtros.departamento_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <div class="grid min-w-0 gap-2">
                <label class="text-xs text-muted-foreground">Puesto</label>
                <SelectSimple
                    :model-value="filtros.puesto_id"
                    :opciones="opcionesDe(opciones.puestos)"
                    placeholder="Todos"
                    opcion-vacia="Todos los puestos"
                    class="w-full"
                    @update:model-value="
                        (v) => {
                            filtros.puesto_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <Button
                variant="ghost"
                size="sm"
                class="justify-self-start"
                @click="limpiar"
            >
                Limpiar filtros
            </Button>
        </div>

        <div
            :class="
                ladoALado
                    ? 'grid items-start gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]'
                    : 'flex flex-col gap-6'
            "
        >
            <div
                v-if="opcionesGrafica"
                data-tour="reportes-grafica"
                class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
            >
                <p class="mb-2 text-sm font-semibold">{{ resultado.titulo }}</p>
                <p
                    v-if="grafica?.recortado"
                    class="mb-2 text-xs text-muted-foreground"
                >
                    Se muestran las categorías con mayor valor; exporta a Excel
                    o PDF para ver el detalle completo.
                </p>
                <VueApexCharts
                    :key="opcionesGrafica.tipoChart"
                    :type="opcionesGrafica.tipoChart"
                    height="280"
                    :options="opcionesGrafica.options"
                    :series="opcionesGrafica.series"
                />
            </div>

            <div
                data-tour="reportes-tabla"
                class="overflow-hidden rounded-2xl border border-border/60 bg-card"
            >
                <div class="border-b border-border/60 px-5 py-3">
                    <h2 class="text-sm font-semibold">
                        {{ resultado.titulo }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{ resultado.filas.length }} resultado(s)
                    </p>
                </div>

                <CrudEmptyState
                    v-if="!resultado.filas.length"
                    :icono="Download"
                    titulo="Sin datos"
                    descripcion="No hay información para este reporte con los filtros seleccionados."
                />

                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border/60 bg-muted/40">
                                <th
                                    v-for="columna in resultado.columnas"
                                    :key="columna"
                                    class="px-4 py-2 text-left font-medium text-muted-foreground"
                                >
                                    {{ columna }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(fila, i) in paginacion.filasPagina
                                    .value"
                                :key="i"
                                class="border-b border-border/40 last:border-0 hover:bg-muted/30"
                            >
                                <td
                                    v-for="(valor, j) in fila"
                                    :key="j"
                                    class="px-4 py-2"
                                >
                                    {{ valor ?? '—' }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <PaginacionTabla
                    local
                    class="mt-3"
                    clave="rh-reportes-resultado"
                    :datos="paginacion.datos.value"
                    :por-pagina="paginacion.porPagina.value"
                    @cambiar-pagina="paginacion.irAPagina"
                    @cambiar-por-pagina="paginacion.cambiarPorPagina"
                />
            </div>
        </div>
    </div>
</template>
