<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import DepartamentoFormDialog from '@/components/Administracion/DepartamentoFormDialog.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/administracion/departamentos';
import type { DepartamentoItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    departamentos: RespuestaPaginada<DepartamentoItem>;
    filtros: { busqueda?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Departamentos', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Departamento' },
    { clave: 'puestos_count', etiqueta: 'Puestos' },
    { clave: 'usuarios_count', etiqueta: 'Colaboradores' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const seleccionado = ref<DepartamentoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(departamento: DepartamentoItem) {
    seleccionado.value = departamento;
    dialogAbierto.value = true;
}

async function eliminar(departamento: DepartamentoItem) {
    const confirmado = await confirmarEliminacion(
        `el departamento «${departamento.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(departamento.id), {
        preserveScroll: true,
        onSuccess: () =>
            mostrarExito('El departamento se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el departamento.'),
    });
}
</script>

<template>
    <Head title="Departamentos" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Departamentos"
                description="Administra los departamentos de la organización"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo departamento
            </Button>
        </div>

        <TableFilters
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre..."
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
            :datos="departamentos"
            mensaje-vacio="No se encontraron departamentos."
        >
            <template #celda-activo="{ fila }">
                <Badge :variant="fila.activo ? 'default' : 'secondary'">{{
                    fila.activo ? 'Activo' : 'Inactivo'
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

    <DepartamentoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :departamento="seleccionado"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
