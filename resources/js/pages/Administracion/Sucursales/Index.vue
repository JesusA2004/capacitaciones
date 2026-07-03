<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import SucursalFormDialog from '@/components/Administracion/SucursalFormDialog.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/administracion/sucursales';
import type { RespuestaPaginada, SucursalItem } from '@/types';

const props = defineProps<{
    sucursales: RespuestaPaginada<SucursalItem>;
    filtros: { busqueda?: string };
    responsablesDisponibles: {
        id: number;
        name: string;
        apellidos: string | null;
    }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Sucursales', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Sucursal' },
    { clave: 'clave', etiqueta: 'Clave' },
    { clave: 'ciudad', etiqueta: 'Ciudad' },
    { clave: 'usuarios_count', etiqueta: 'Colaboradores' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const sucursalSeleccionada = ref<SucursalItem | null>(null);

function abrirCrear() {
    sucursalSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(sucursal: SucursalItem) {
    sucursalSeleccionada.value = sucursal;
    dialogAbierto.value = true;
}

async function eliminar(sucursal: SucursalItem) {
    const confirmado = await confirmarEliminacion(
        `la sucursal «${sucursal.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(sucursal.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La sucursal se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar la sucursal.'),
    });
}
</script>

<template>
    <Head title="Sucursales" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Sucursales"
                description="Administra las sucursales de la organización"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva sucursal
            </Button>
        </div>

        <TableFilters
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre o clave..."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @limpiar="limpiar"
        />

        <DataTable
            :columnas="columnas"
            :datos="sucursales"
            mensaje-vacio="No se encontraron sucursales."
        >
            <template #celda-usuarios_count="{ fila }">
                {{ fila.usuarios_count }}
            </template>
            <template #celda-activo="{ fila }">
                <Badge :variant="fila.activo ? 'default' : 'secondary'">{{
                    fila.activo ? 'Activa' : 'Inactiva'
                }}</Badge>
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Editar"
                        @click="abrirEditar(fila)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Eliminar"
                        @click="eliminar(fila)"
                    >
                        <Trash2 class="size-4 text-destructive" />
                    </Button>
                </div>
            </template>
        </DataTable>
    </div>

    <SucursalFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :sucursal="sucursalSeleccionada"
        :responsables-disponibles="responsablesDisponibles"
        :key="sucursalSeleccionada?.id ?? 'nueva'"
    />
</template>
