<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IdCard, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import AltaDigitalFormDialog from '@/components/Rh/AltaDigitalFormDialog.vue';
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
import { index, show } from '@/routes/rh/altas';
import type {
    AltaDigitalItem,
    OpcionesReclutamiento,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    altas: RespuestaPaginada<AltaDigitalItem>;
    filtros: { estado?: string };
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

const { filtros, aplicar } = useFiltros(index.url(), {
    estado: props.filtros.estado ?? '',
});

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
            <Button @click="dialogoAbierto = true">
                <Plus class="size-4" />
                Nueva alta
            </Button>
        </CrudPageHeader>

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
