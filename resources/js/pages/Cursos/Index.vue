<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { BookOpen, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import CursoFormDialog from '@/components/Cursos/CursoFormDialog.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
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
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, edit, index } from '@/routes/cursos';
import type { CursoItem, EstadoCursoOpcion, RespuestaPaginada } from '@/types';

const props = defineProps<{
    cursos: RespuestaPaginada<CursoItem>;
    filtros: { busqueda?: string; estado?: string };
    estados: EstadoCursoOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Cursos', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, aplicar, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        estado: props.filtros.estado ?? '',
    },
);
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'titulo', etiqueta: 'Curso' },
    { clave: 'modulos_count', etiqueta: 'Módulos' },
    { clave: 'estado', etiqueta: 'Estado' },
    { clave: 'responsable', etiqueta: 'Responsable' },
];

const dialogAbierto = ref(false);

function variantePorEstado(
    estado: string,
): 'default' | 'secondary' | 'outline' {
    if (estado === 'publicado') {
        return 'default';
    }

    if (estado === 'archivado') {
        return 'outline';
    }

    return 'secondary';
}

async function eliminar(curso: CursoItem) {
    const confirmado = await confirmarEliminacion(`el curso «${curso.titulo}»`);

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(curso.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El curso se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el curso.'),
    });
}
</script>

<template>
    <Head title="Cursos" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Cursos"
                description="Administra los cursos de capacitación e inducción"
            />
            <Button @click="dialogAbierto = true">
                <Plus class="size-4" />
                Nuevo curso
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <TableFilters
                :model-value="filtros.busqueda"
                placeholder="Buscar por título..."
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                @limpiar="limpiar"
            >
                <Select
                    :model-value="filtros.estado"
                    @update:model-value="
                        (v) => {
                            filtros.estado = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todos los estados"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in estados"
                            :key="opcion.value"
                            :value="opcion.value"
                        >
                            {{ opcion.etiqueta }}
                        </SelectItem>
                    </SelectContent>
                </Select>
            </TableFilters>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="cursos"
            mensaje-vacio="No se encontraron cursos."
        >
            <template #celda-estado="{ fila }">
                <Badge :variant="variantePorEstado(fila.estado)">{{
                    estados.find((e) => e.value === fila.estado)?.etiqueta ??
                    fila.estado
                }}</Badge>
            </template>
            <template #celda-responsable="{ fila }">
                {{
                    fila.responsable
                        ? `${fila.responsable.name} ${fila.responsable.apellidos ?? ''}`
                        : 'Sin asignar'
                }}
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Abrir constructor"
                        as-child
                    >
                        <Link :href="edit(fila.id)">
                            <BookOpen class="size-4" />
                        </Link>
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

    <CursoFormDialog v-if="dialogAbierto" v-model:open="dialogAbierto" />
</template>
