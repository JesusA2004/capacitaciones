<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { HelpCircle, Library, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import BancoFormDialog from '@/components/Cuestionarios/BancoFormDialog.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index, show } from '@/routes/bancos-preguntas';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import type { BancoPreguntaItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    bancos: RespuestaPaginada<BancoPreguntaItem>;
    filtros: { busqueda?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
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

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Banco de preguntas"
            descripcion="Preguntas reutilizables entre los cuestionarios de los cursos."
            :icono="Library"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo banco
            </Button>
        </CrudPageHeader>

        <TableFilters
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre…"
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
                <Link
                    :href="show.url(fila.id)"
                    class="group flex items-center gap-3"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-crema text-bronce transicion-ui group-hover:scale-105"
                        aria-hidden="true"
                    >
                        <Library class="size-4" />
                    </span>
                    <span
                        class="font-medium group-hover:text-primary group-hover:underline"
                        >{{ fila.nombre }}</span
                    >
                </Link>
            </template>
            <template #celda-descripcion="{ fila }">
                <span class="line-clamp-2 text-muted-foreground">{{
                    fila.descripcion || '—'
                }}</span>
            </template>
            <template #celda-preguntas_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground tabular-nums"
                >
                    <HelpCircle class="size-3.5" aria-hidden="true" />
                    {{ fila.preguntas_count ?? 0 }}
                </span>
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Editar banco"
                        aria-label="Editar banco"
                        @click="abrirEditar(fila)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Eliminar banco"
                        aria-label="Eliminar banco"
                        @click="eliminar(fila)"
                    >
                        <Trash2 class="size-4 text-destructive" />
                    </Button>
                </div>
            </template>
        </DataTable>
    </div>

    <BancoFormDialog
        v-model:open="dialogAbierto"
        :banco="bancoSeleccionado"
        :key="bancoSeleccionado?.id ?? 'nuevo'"
    />
</template>
