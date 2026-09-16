<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import VueApexCharts from 'vue3-apexcharts';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { exportar, exportarPdf, index } from '@/routes/reportes/cumplimiento';
import type { ColaboradorCumplimientoItem, GraficaReporte, RespuestaPaginada } from '@/types';

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
            colors: ['#ef4444', '#f59e0b', '#60a5fa', 'var(--brand-primary)'],
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

    <div class="flex flex-col gap-6 p-4">
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
                <Select
                    :model-value="filtros.sucursal_id"
                    @update:model-value="
                        (v) => {
                            filtros.sucursal_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-48">
                        <SelectValue placeholder="Todas" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="sucursal in sucursales"
                            :key="sucursal.id"
                            :value="String(sucursal.id)"
                        >
                            {{ sucursal.nombre }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground"
                    >Departamento</label
                >
                <Select
                    :model-value="filtros.departamento_id"
                    @update:model-value="
                        (v) => {
                            filtros.departamento_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-48">
                        <SelectValue placeholder="Todos" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="departamento in departamentos"
                            :key="departamento.id"
                            :value="String(departamento.id)"
                        >
                            {{ departamento.nombre }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground">Curso</label>
                <Select
                    :model-value="filtros.curso_id"
                    @update:model-value="
                        (v) => {
                            filtros.curso_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-56">
                        <SelectValue placeholder="Todos" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="curso in cursos"
                            :key="curso.id"
                            :value="String(curso.id)"
                        >
                            {{ curso.titulo }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <div
            v-if="opcionesGrafica"
            class="rounded-2xl border border-border/60 bg-card p-4 transition-shadow duration-200 hover:shadow-md"
        >
            <p class="mb-2 text-sm font-semibold">Distribución por rango de cumplimiento</p>
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
