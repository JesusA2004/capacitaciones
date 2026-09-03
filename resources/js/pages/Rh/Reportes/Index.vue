<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BarChart3, Download, FileSpreadsheet, FileText } from '@lucide/vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
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
import { excel, index, pdf } from '@/routes/rh/reportes';
import type {
    FiltrosReporteRh,
    GrupoCatalogoReportes,
    OpcionesReportesRh,
    ResultadoReporteRh,
} from '@/types';

const props = defineProps<{
    catalogo: GrupoCatalogoReportes[];
    reporte: string;
    filtros: FiltrosReporteRh;
    resultado: ResultadoReporteRh;
    puedeExportar: boolean;
    opciones: OpcionesReportesRh;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reportes RH', href: index.url() },
        ],
    },
});

const { filtros, aplicar } = useFiltros(index.url(), {
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
</script>

<template>
    <Head :title="resultado.titulo" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Reportes RH"
            descripcion="Consulta y exporta la información de RH filtrada por empresa, sucursal, departamento o puesto."
            :icono="BarChart3"
        >
            <template v-if="puedeExportar">
                <Button as-child variant="outline" size="sm">
                    <a :href="urlExportar(excel)">
                        <FileSpreadsheet class="size-4" />
                        Excel
                    </a>
                </Button>
                <Button as-child variant="outline" size="sm">
                    <a :href="urlExportar(pdf)">
                        <FileText class="size-4" />
                        PDF
                    </a>
                </Button>
            </template>
        </CrudPageHeader>

        <div class="flex flex-wrap items-end gap-3">
            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground">Reporte</label>
                <Select
                    :model-value="filtros.reporte"
                    @update:model-value="
                        (v) => {
                            filtros.reporte = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-72">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <template v-for="grupo in catalogo" :key="grupo.grupo">
                            <div
                                class="px-2 py-1.5 text-xs font-semibold text-muted-foreground"
                            >
                                {{ grupo.grupo }}
                            </div>
                            <SelectItem
                                v-for="opcion in grupo.opciones"
                                :key="opcion.value"
                                :value="opcion.value"
                            >
                                {{ opcion.label }}
                            </SelectItem>
                        </template>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground">Empresa</label>
                <Select
                    :model-value="filtros.empresa_id"
                    @update:model-value="
                        (v) => {
                            filtros.empresa_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todas"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in opciones.empresas"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>

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
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todas"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in opciones.sucursales"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
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
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todos"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in opciones.departamentos"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <label class="text-xs text-muted-foreground">Puesto</label>
                <Select
                    :model-value="filtros.puesto_id"
                    @update:model-value="
                        (v) => {
                            filtros.puesto_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todos"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in opciones.puestos"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </div>

        <div
            class="overflow-hidden rounded-2xl border border-border/60 bg-card"
        >
            <div class="border-b border-border/60 px-5 py-3">
                <h2 class="text-sm font-semibold">{{ resultado.titulo }}</h2>
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
                            v-for="(fila, i) in resultado.filas"
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
        </div>
    </div>
</template>
