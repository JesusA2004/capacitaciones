<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import BancoFormDialog from '@/components/Cuestionarios/BancoFormDialog.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index, show } from '@/routes/bancos-preguntas';
import type { BancoPreguntaItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    bancos: RespuestaPaginada<BancoPreguntaItem>;
    filtros: { busqueda?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Banco de preguntas', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Nombre' },
    { clave: 'descripcion', etiqueta: 'Descripción' },
    { clave: 'preguntas_count', etiqueta: 'Preguntas' },
];

const dialogAbierto = ref(false);
const bancoSeleccionado = ref<BancoPreguntaItem | null>(null);

function abrirCrear() {
    bancoSeleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(banco: BancoPreguntaItem) {
    bancoSeleccionado.value = banco;
    dialogAbierto.value = true;
}

async function eliminar(banco: BancoPreguntaItem) {
    const confirmado = await confirmarEliminacion(`el banco «${banco.nombre}»`);

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(banco.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El banco se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el banco.'),
    });
}
</script>

<template>
    <Head title="Banco de preguntas" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Banco de preguntas"
                description="Preguntas reutilizables entre los cuestionarios de los cursos"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo banco
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
            :datos="bancos"
            mensaje-vacio="Todavía no se ha creado ningún banco de preguntas."
        >
            <template #celda-nombre="{ fila }">
                <Link :href="show.url(fila.id)" class="font-medium underline">
                    {{ fila.nombre }}
                </Link>
            </template>
            <template #acciones="{ fila }">
                <Button variant="ghost" size="sm" @click="abrirEditar(fila)"
                    >Editar</Button
                >
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-destructive"
                    @click="eliminar(fila)"
                    >Eliminar</Button
                >
            </template>
        </DataTable>
    </div>

    <BancoFormDialog
        v-model:open="dialogAbierto"
        :banco="bancoSeleccionado"
        :key="bancoSeleccionado?.id ?? 'nuevo'"
    />
</template>
