<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { BarChart3, Download, FileOutput, Plus, Settings2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import InputError from '@/components/InputError.vue';
import PanelColumnasArrastre from '@/components/reportes/PanelColumnasArrastre.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useFiltros } from '@/composables/useFiltros';
import { usePaginacionLocal } from '@/composables/usePaginacion';
import { VueApexCharts } from '@/lib/apexDiferido';
import { opcionesDe } from '@/lib/opcionesSelect';
import { dashboard } from '@/routes';
import { excel, index, pdf } from '@/routes/rh/reportes';
import { index as misReportes } from '@/routes/rh/reportes/mis-reportes';
import {
    exportar as exportarNoi,
    layout as layoutNoi,
} from '@/routes/rh/reportes/noi';
import type {
    FiltrosReporteRh,
    GraficaReporte,
    GrupoCatalogoReportes,
    OpcionesReportesRh,
    ResultadoReporteRh,
} from '@/types';

type CampoNoi = { clave: string; etiqueta: string; encabezado?: string };

const props = defineProps<{
    catalogo: GrupoCatalogoReportes[];
    reporte: string;
    filtros: FiltrosReporteRh;
    resultado: ResultadoReporteRh;
    grafica: GraficaReporte | null;
    puedeExportar: boolean;
    puede: {
        mis_reportes: boolean;
        noi_configurar: boolean;
        noi_exportar: boolean;
    };
    guardados: { id: number; nombre: string }[];
    noi: {
        layout: {
            formato: 'xlsx' | 'csv';
            columnas: { campo: string; encabezado: string }[];
        };
        campos: { clave: string; etiqueta: string }[];
    } | null;
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
    fecha_inicio: props.filtros.fecha_inicio ?? '',
    fecha_fin: props.filtros.fecha_fin ?? '',
    mes: props.filtros.mes ?? '',
});

const opcionActual = computed(() =>
    props.catalogo
        .flatMap((g) => g.opciones)
        .find((o) => o.value === props.reporte),
);

function elegir(clave: string) {
    filtros.reporte = clave;
    aplicar();
}

function urlExportar(destino: { url: () => string }): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor) as [
            string,
            string,
        ][],
    );

    return `${destino.url()}?${parametros.toString()}`;
}

const MESES = [
    'Enero',
    'Febrero',
    'Marzo',
    'Abril',
    'Mayo',
    'Junio',
    'Julio',
    'Agosto',
    'Septiembre',
    'Octubre',
    'Noviembre',
    'Diciembre',
].map((label, i) => ({ value: String(i + 1), label }));

// Tokens de la paleta oficial, no hex sueltos.
const paletaMarca = [
    'var(--brand-primary)',
    'var(--brand-secondary)',
    'var(--pastel-oro, var(--brand-primary))',
    'var(--pastel-bronce, var(--brand-secondary))',
];

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

// Layout configurable de Altas para NOI.
const editandoLayout = ref(false);
const columnasNoi = ref<CampoNoi[]>([]);
const formLayout = useForm<{
    columnas: { campo: string; encabezado: string }[];
    formato: 'xlsx' | 'csv';
}>({ columnas: [], formato: 'xlsx' });

function abrirLayout() {
    const noi = props.noi;

    if (!noi) {
        return;
    }

    columnasNoi.value = noi.layout.columnas.map((c) => ({
        clave: c.campo,
        etiqueta:
            noi.campos.find((x) => x.clave === c.campo)?.etiqueta ?? c.campo,
        encabezado: c.encabezado,
    }));
    formLayout.formato = noi.layout.formato;
    editandoLayout.value = true;
}

function guardarLayout() {
    formLayout
        .transform((d) => ({
            ...d,
            columnas: columnasNoi.value.map((c) => ({
                campo: c.clave,
                encabezado: c.encabezado ?? c.etiqueta,
            })),
        }))
        .put(layoutNoi.url(), {
            preserveScroll: true,
            onSuccess: () => {
                editandoLayout.value = false;
            },
        });
}
</script>

<template>
    <Head :title="resultado.titulo" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Reportes de RH"
            descripcion="Reportes de trabajo por persona, con filtros y descarga."
            :icono="BarChart3"
        >
            <template v-if="puedeExportar">
                <CrudExportButtons
                    :url-excel="urlExportar(excel)"
                    :url-pdf="urlExportar(pdf)"
                />
            </template>
        </CrudPageHeader>

        <div class="grid items-start gap-5 lg:grid-cols-[17rem_minmax(0,1fr)]">
            <nav
                class="rounded-2xl border border-border/60 bg-card p-3 lg:sticky lg:top-4"
                aria-label="Catálogo de reportes"
                data-tour="reportes-tipo"
            >
                <div v-for="grupo in catalogo" :key="grupo.grupo" class="mb-3">
                    <p
                        class="mb-1 px-2 text-[11px] font-semibold tracking-wide text-bronce uppercase"
                    >
                        {{ grupo.grupo }}
                    </p>
                    <button
                        v-for="opcion in grupo.opciones"
                        :key="opcion.value"
                        type="button"
                        class="block w-full rounded-lg px-2 py-1.5 text-left text-sm transition-colors"
                        :class="
                            reporte === opcion.value
                                ? 'bg-salvia font-medium'
                                : 'hover:bg-muted/60'
                        "
                        :aria-current="
                            reporte === opcion.value ? 'page' : undefined
                        "
                        @click="elegir(opcion.value)"
                    >
                        {{ opcion.label }}
                    </button>
                </div>

                <div
                    v-if="puede.mis_reportes"
                    class="border-t border-border/60 pt-3"
                >
                    <p
                        class="mb-1 px-2 text-[11px] font-semibold tracking-wide text-bronce uppercase"
                    >
                        Mis reportes
                    </p>
                    <Link
                        v-for="g in guardados"
                        :key="g.id"
                        :href="misReportes.url({ query: { reporte: g.id } })"
                        class="block rounded-lg px-2 py-1.5 text-sm hover:bg-muted/60"
                    >
                        {{ g.nombre }}
                    </Link>
                    <Link
                        :href="misReportes.url()"
                        class="flex items-center gap-1 rounded-lg px-2 py-1.5 text-sm font-medium text-bronce hover:bg-muted/60"
                    >
                        <Plus class="size-4" /> Crear reporte
                    </Link>
                </div>

                <p
                    class="mt-3 rounded-lg bg-crema/60 p-2 text-xs text-muted-foreground"
                >
                    Rotación de personal ahora vive en Cierres laborales →
                    Bajas.
                </p>
            </nav>

            <div class="flex min-w-0 flex-col gap-5">
                <div
                    data-tour="reportes-filtros"
                    class="grid items-end gap-3 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <template v-if="opcionActual?.periodo">
                        <label
                            class="grid min-w-0 gap-2 text-xs text-muted-foreground"
                        >
                            Desde
                            <Input
                                v-model="filtros.fecha_inicio"
                                type="date"
                                @change="aplicar()"
                            />
                        </label>
                        <label
                            class="grid min-w-0 gap-2 text-xs text-muted-foreground"
                        >
                            Hasta
                            <Input
                                v-model="filtros.fecha_fin"
                                type="date"
                                @change="aplicar()"
                            />
                        </label>
                    </template>
                    <div
                        v-if="opcionActual?.mes"
                        class="grid min-w-0 gap-2 text-xs text-muted-foreground"
                    >
                        Mes de aniversario
                        <SelectSimple
                            :model-value="filtros.mes"
                            :opciones="MESES"
                            placeholder="Mes en curso"
                            opcion-vacia="Mes en curso"
                            class="w-full"
                            @update:model-value="
                                (v) => {
                                    filtros.mes = String(v ?? '');
                                    aplicar();
                                }
                            "
                        />
                    </div>
                    <div class="grid min-w-0 gap-2">
                        <label class="text-xs text-muted-foreground"
                            >Empresa</label
                        >
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
                        <label class="text-xs text-muted-foreground"
                            >Sucursal</label
                        >
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
                        <label class="text-xs text-muted-foreground"
                            >Puesto</label
                        >
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
                    v-for="aviso in resultado.avisos ?? []"
                    :key="aviso"
                    class="rounded-lg bg-warning-soft px-3 py-2 text-sm text-warning"
                >
                    {{ aviso }}
                </div>

                <section
                    v-if="
                        reporte === 'altas_noi' &&
                        (puede.noi_exportar || puede.noi_configurar)
                    "
                    class="flex flex-wrap items-center gap-2 rounded-2xl border border-border/60 bg-card p-3"
                >
                    <p class="mr-auto text-sm text-muted-foreground">
                        <template v-if="resultado.faltantes">
                            {{ resultado.faltantes }} persona(s) con datos
                            faltantes: revísalos antes de exportar.
                        </template>
                        <template v-else>
                            Sin datos faltantes en este periodo.
                        </template>
                    </p>
                    <Button
                        v-if="puede.noi_configurar && noi"
                        variant="outline"
                        size="sm"
                        @click="abrirLayout"
                    >
                        <Settings2 class="size-4" /> Configurar layout
                    </Button>
                    <Button v-if="puede.noi_exportar" size="sm" as-child>
                        <a :href="urlExportar(exportarNoi)">
                            <FileOutput class="size-4" /> Exportar layout NOI
                        </a>
                    </Button>
                </section>

                <section
                    v-if="editandoLayout && noi"
                    class="rounded-2xl border border-border/60 bg-card p-4"
                    aria-label="Layout de Altas para NOI"
                >
                    <PanelColumnasArrastre
                        v-model="columnasNoi"
                        :disponibles="noi.campos"
                        titulo-disponibles="Datos de People"
                        titulo-seleccion="Columnas del layout"
                    >
                        <template #detalle="{ campo }">
                            <Input
                                :model-value="(campo as CampoNoi).encabezado"
                                class="mt-1 h-7 text-xs"
                                maxlength="80"
                                @update:model-value="
                                    (v) =>
                                        ((campo as CampoNoi).encabezado =
                                            String(v))
                                "
                                :aria-label="`Encabezado de ${campo.etiqueta}`"
                                :placeholder="campo.etiqueta"
                            />
                        </template>
                    </PanelColumnasArrastre>
                    <InputError
                        class="mt-2"
                        :message="
                            (formLayout.errors as Record<string, string>)
                                .columnas
                        "
                    />
                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <SelectSimple
                            v-model="formLayout.formato"
                            :opciones="[
                                {
                                    value: 'xlsx',
                                    label: 'Archivo Excel (.xlsx)',
                                },
                                {
                                    value: 'csv',
                                    label: 'Archivo de texto (.csv)',
                                },
                            ]"
                            class="w-56"
                        />
                        <Button
                            size="sm"
                            :disabled="
                                formLayout.processing || !columnasNoi.length
                            "
                            @click="guardarLayout"
                        >
                            Guardar layout
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            @click="editandoLayout = false"
                        >
                            Cancelar
                        </Button>
                    </div>
                </section>

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
                        <p class="mb-2 text-sm font-semibold">
                            {{ resultado.titulo }}
                        </p>
                        <p
                            v-if="grafica?.recortado"
                            class="mb-2 text-xs text-muted-foreground"
                        >
                            Se muestran las categorías con mayor valor; exporta
                            a Excel o PDF para ver el detalle completo.
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
                                    <tr
                                        class="border-b border-border/60 bg-muted/40"
                                    >
                                        <th
                                            v-for="columna in resultado.columnas"
                                            :key="columna"
                                            class="px-4 py-2 text-left font-medium whitespace-nowrap text-muted-foreground"
                                        >
                                            {{ columna }}
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="(fila, i) in paginacion
                                            .filasPagina.value"
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
        </div>
    </div>
</template>
