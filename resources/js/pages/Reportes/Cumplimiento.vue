<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFiltros } from '@/composables/useFiltros';
import { VueApexCharts } from '@/lib/apexDiferido';
import { dashboard } from '@/routes';
import { exportar, exportarPdf, index } from '@/routes/reportes/cumplimiento';
import type {
    ColaboradorCumplimientoItem,
    GraficaReporte,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    colaboradores: RespuestaPaginada<ColaboradorCumplimientoItem>;
    filtros: {
        sucursal_id?: string;
        departamento_id?: string;
        curso_id?: string;
    };
    grafica: GraficaReporte | null;
    puedeExportar: boolean;
    sucursales: { id: number; nombre: string }[];
    departamentos: { id: number; nombre: string }[];
    cursos: { id: number; titulo: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reporte de cumplimiento', href: index.url() },
        ],
    },
});

const { filtros, aplicar, limpiar } = useFiltros(index.url(), {
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    curso_id: props.filtros.curso_id ?? '',
});

const columnas: ColumnaDataTable[] = [
    { clave: 'name', etiqueta: 'Colaborador' },
    { clave: 'sucursal', etiqueta: 'Sucursal' },
    { clave: 'departamento', etiqueta: 'Departamento' },
    { clave: 'asignaciones_total', etiqueta: 'Asignadas' },
    { clave: 'asignaciones_completadas', etiqueta: 'Completadas' },
    { clave: 'asignaciones_vencidas', etiqueta: 'Vencidas' },
    { clave: 'cumplimiento', etiqueta: 'Cumplimiento' },
];

function porcentaje(fila: ColaboradorCumplimientoItem): number {
    return fila.asignaciones_total > 0
        ? Math.round(
              (fila.asignaciones_completadas / fila.asignaciones_total) * 100,
          )
        : 0;
}

function urlExportar(destino: { url: () => string }): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

const opcionesGrafica = computed(() => {
    const g = props.grafica;

    if (!g) {
        return null;
    }

    return {
        options: {
            chart: { toolbar: { show: false }, fontFamily: 'inherit' },
            colors: [
                'var(--destructive)',
                'var(--pastel-oro)',
                'var(--pastel-esmeralda)',
                'var(--brand-primary)',
            ],
            dataLabels: { enabled: true },
            legend: { position: 'bottom' as const },
            labels: g.categorias,
        },
        series: g.series[0]?.valores ?? [],
    };
});
</script>

<template>
    <Head title="Reporte de cumplimiento" />

    <div class="pagina-ancha flex flex-col gap-6">
        <div class="flex items-center justify-between">
            <Heading
                title="Reporte de cumplimiento"
                description="Progreso de capacitación por colaborador"
            />
            <div v-if="puedeExportar" class="flex items-center gap-2">
                <CrudExportButtons
                    :url-excel="urlExportar(exportar)"
                    :url-pdf="urlExportar(exportarPdf)"
                />
            </div>
        </div>

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground">Sucursal</label>
                <SelectSimple
                    :model-value="filtros.sucursal_id"
                    :opciones="
                        sucursales.map((o) => ({
                            value: String(o.id),
                            label: o.nombre,
                        }))
                    "
                    placeholder="Todas"
                    opcion-vacia="Todas"
                    placeholder-busqueda="Buscar sucursal…"
                    class="w-48"
                    @update:model-value="
                        (v) => {
                            filtros.sucursal_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground"
                    >Departamento</label
                >
                <SelectSimple
                    :model-value="filtros.departamento_id"
                    :opciones="
                        departamentos.map((o) => ({
                            value: String(o.id),
                            label: o.nombre,
                        }))
                    "
                    placeholder="Todos"
                    opcion-vacia="Todos"
                    placeholder-busqueda="Buscar departamento…"
                    class="w-48"
                    @update:model-value="
                        (v) => {
                            filtros.departamento_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground">Curso</label>
                <SelectSimple
                    :model-value="filtros.curso_id"
                    :opciones="
                        cursos.map((o) => ({
                            value: String(o.id),
                            label: o.titulo,
                        }))
                    "
                    placeholder="Todos"
                    opcion-vacia="Todos"
                    placeholder-busqueda="Buscar curso…"
                    class="w-56"
                    @update:model-value="
                        (v) => {
                            filtros.curso_id = String(v ?? '');
                            aplicar();
                        }
                    "
                />
            </div>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <div
            v-if="opcionesGrafica"
            class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
        >
            <p class="mb-2 text-sm font-semibold">
                Distribución por rango de cumplimiento
            </p>
            <VueApexCharts
                type="donut"
                height="280"
                :options="opcionesGrafica.options"
                :series="opcionesGrafica.series"
            />
        </div>

        <DataTable
            :columnas="columnas"
            :datos="colaboradores"
            mensaje-vacio="No hay colaboradores para mostrar con estos filtros."
        >
            <template #celda-name="{ fila }">
                {{ fila.name }} {{ fila.apellidos ?? '' }}
            </template>
            <template #celda-sucursal="{ fila }">
                {{ fila.sucursal_principal?.nombre ?? '—' }}
            </template>
            <template #celda-departamento="{ fila }">
                {{ fila.departamento?.nombre ?? '—' }}
            </template>
            <template #celda-cumplimiento="{ fila }">
                <Badge variant="secondary">{{ porcentaje(fila) }}%</Badge>
            </template>
        </DataTable>
    </div>
</template>
