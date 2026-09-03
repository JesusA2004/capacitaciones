<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FileSpreadsheet, FileText, IdCard, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import AltaDigitalFormDialog from '@/components/Rh/AltaDigitalFormDialog.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { exportarExcel, exportarPdf, index, show } from '@/routes/rh/altas';
import type {
    AltaDigitalItem,
    OpcionesReclutamiento,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    altas: RespuestaPaginada<AltaDigitalItem>;
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        estado?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    opciones: OpcionesReclutamiento;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Altas digitales', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        estado: props.filtros.estado ?? '',
        busqueda: props.filtros.busqueda ?? '',
        fecha_inicio: props.filtros.fecha_inicio ?? '',
        fecha_fin: props.filtros.fecha_fin ?? '',
    },
);
const filtroSheetAbierto = ref(false);
function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Candidato' },
    { clave: 'puesto', etiqueta: 'Puesto' },
    { clave: 'sucursal', etiqueta: 'Sucursal' },
    { clave: 'estado', etiqueta: 'Estado' },
];

const dialogoAbierto = ref(false);
</script>

<template>
    <Head title="Altas digitales" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Altas digitales"
            descripcion="Liga segura para que candidatos aprobados capturen su información de alta."
            :icono="IdCard"
        >
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarExcel)">
                    <FileSpreadsheet class="size-4" />
                    Excel
                </a>
            </Button>
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarPdf)">
                    <FileText class="size-4" />
                    PDF
                </a>
            </Button>
            <Button @click="dialogoAbierto = true">
                <Plus class="size-4" />
                Nueva alta
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre, correo o CURP..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
            />

            <Select
                :model-value="filtros.estado"
                @update:model-value="
                    (v) => {
                        filtros.estado = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-56"
                    ><SelectValue placeholder="Todos los estados"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.estados"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Empresa, sucursal, departamento, puesto y fecha de creación."
                :contador-activos="
                    [
                        filtros.empresa_id,
                        filtros.sucursal_id,
                        filtros.departamento_id,
                        filtros.puesto_id,
                        filtros.fecha_inicio,
                        filtros.fecha_fin,
                    ].filter(Boolean).length
                "
                :open="filtroSheetAbierto"
                @update:open="(v) => (filtroSheetAbierto = v)"
                @aplicar="aplicar"
                @limpiar="limpiar"
            >
                <div class="grid gap-2">
                    <Label>Empresa</Label>
                    <Select
                        :model-value="filtros.empresa_id"
                        @update:model-value="
                            (v) => (filtros.empresa_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
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
                    <Label>Sucursal</Label>
                    <Select
                        :model-value="filtros.sucursal_id"
                        @update:model-value="
                            (v) => (filtros.sucursal_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
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
                    <Label>Departamento</Label>
                    <Select
                        :model-value="filtros.departamento_id"
                        @update:model-value="
                            (v) => (filtros.departamento_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
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
                    <Label>Puesto</Label>
                    <Select
                        :model-value="filtros.puesto_id"
                        @update:model-value="
                            (v) => (filtros.puesto_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
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

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Creada desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Creada hasta</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_fin"
                            @update:model-value="
                                (v) => (filtros.fecha_fin = String(v ?? ''))
                            "
                        />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="altas"
            mensaje-vacio="No se encontraron altas digitales."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="IdCard"
                    titulo="Todavía no hay altas digitales"
                    descripcion="Genera una desde un candidato aprobado o crea un preregistro manual."
                >
                    <Button size="sm" @click="dialogoAbierto = true">
                        <Plus class="size-4" />
                        Nueva alta
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-nombre="{ fila }">
                {{
                    `${fila.nombre ?? ''} ${fila.apellidos ?? ''}`.trim() ||
                    'Sin nombre'
                }}
            </template>
            <template #celda-puesto="{ fila }">
                {{ fila.puesto?.nombre ?? '—' }}
            </template>
            <template #celda-sucursal="{ fila }">
                {{ fila.sucursal?.nombre ?? '—' }}
            </template>
            <template #celda-estado="{ fila }">
                <EstadoBadge :estado="fila.estado" />
            </template>
            <template #acciones="{ fila }">
                <Link
                    :href="show.url(fila.id)"
                    class="text-sm font-medium text-[var(--brand-primary)] hover:underline"
                    >Ver</Link
                >
            </template>
        </DataTable>
    </div>

    <AltaDigitalFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :opciones="opciones"
    />
</template>
