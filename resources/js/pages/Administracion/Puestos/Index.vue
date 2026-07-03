<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import PuestoFormDialog from '@/components/Administracion/PuestoFormDialog.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/administracion/puestos';
import type { OpcionSimple, PuestoItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    puestos: RespuestaPaginada<PuestoItem>;
    filtros: { busqueda?: string };
    departamentosDisponibles: OpcionSimple[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Puestos', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Puesto' },
    { clave: 'departamento', etiqueta: 'Departamento' },
    { clave: 'usuarios_count', etiqueta: 'Colaboradores' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const seleccionado = ref<PuestoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(puesto: PuestoItem) {
    seleccionado.value = puesto;
    dialogAbierto.value = true;
}

async function eliminar(puesto: PuestoItem) {
    const confirmado = await confirmarEliminacion(
        `el puesto «${puesto.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(puesto.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El puesto se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el puesto.'),
    });
}
</script>

<template>
    <Head title="Puestos" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Puestos"
                description="Administra los puestos de la organización"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo puesto
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
            :datos="puestos"
            mensaje-vacio="No se encontraron puestos."
        >
            <template #celda-departamento="{ fila }">
                {{ fila.departamento?.nombre ?? 'Sin departamento' }}
            </template>
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

    <PuestoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :puesto="seleccionado"
        :departamentos-disponibles="departamentosDisponibles"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
