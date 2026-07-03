<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { FileText, Image as ImageIcon, Plus, Trash2, Video } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import type { Component } from 'vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import Heading from '@/components/Heading.vue';
import MultimediaUploadDialog from '@/components/Multimedia/MultimediaUploadDialog.vue';
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
import { destroy, index } from '@/routes/multimedia';
import type {
    RecursoMultimediaItem,
    RespuestaPaginada,
    TipoRecursoOpcion,
} from '@/types';

const props = defineProps<{
    recursos: RespuestaPaginada<RecursoMultimediaItem>;
    filtros: { tipo?: string; busqueda?: string };
    tipos: TipoRecursoOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Biblioteca multimedia', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, aplicar, limpiar } = useFiltros(
    index.url(),
    {
        tipo: props.filtros.tipo ?? '',
        busqueda: props.filtros.busqueda ?? '',
    },
);
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre_original', etiqueta: 'Archivo' },
    { clave: 'tipo', etiqueta: 'Tipo' },
    { clave: 'estado', etiqueta: 'Estado' },
    { clave: 'subidoPor', etiqueta: 'Cargado por' },
];

const dialogAbierto = ref(false);

const iconosPorTipo: Record<string, Component> = {
    video: Video,
    documento: FileText,
    imagen: ImageIcon,
};

function variantePorEstado(
    estado: string,
): 'default' | 'secondary' | 'outline' {
    if (estado === 'disponible') {
        return 'default';
    }

    if (estado === 'error') {
        return 'outline';
    }

    return 'secondary';
}

const hayProcesamientoPendiente = computed(() =>
    props.recursos.data.some(
        (recurso) =>
            recurso.estado === 'pendiente' || recurso.estado === 'procesando',
    ),
);

let intervalo: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    intervalo = setInterval(() => {
        if (hayProcesamientoPendiente.value) {
            router.reload({ only: ['recursos'] });
        }
    }, 5000);
});

onUnmounted(() => {
    clearInterval(intervalo);
});

async function eliminar(recurso: RecursoMultimediaItem) {
    const confirmado = await confirmarEliminacion(
        `el recurso «${recurso.nombre_original}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(recurso.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El recurso se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el recurso.'),
    });
}
</script>

<template>
    <Head title="Biblioteca multimedia" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Biblioteca multimedia"
                description="Videos, documentos e imágenes usados en las lecciones"
            />
            <Button @click="dialogAbierto = true">
                <Plus class="size-4" />
                Cargar archivo
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
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
            >
                <Select
                    :model-value="filtros.tipo"
                    @update:model-value="
                        (v) => {
                            filtros.tipo = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
                        ><SelectValue placeholder="Todos los tipos"
                    /></SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in tipos"
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
            :datos="recursos"
            mensaje-vacio="Todavía no se ha cargado ningún archivo."
        >
            <template #celda-nombre_original="{ fila }">
                <div class="flex items-center gap-2">
                    <component
                        :is="iconosPorTipo[fila.tipo] ?? FileText"
                        class="size-4 shrink-0 text-muted-foreground"
                    />
                    <span class="truncate">{{ fila.nombre_original }}</span>
                </div>
            </template>
            <template #celda-estado="{ fila }">
                <Badge :variant="variantePorEstado(fila.estado)">{{
                    fila.estado
                }}</Badge>
                <p
                    v-if="fila.estado === 'error' && fila.error_procesamiento"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ fila.error_procesamiento }}
                </p>
            </template>
            <template #celda-subidoPor="{ fila }">
                {{
                    fila.subidoPor
                        ? `${fila.subidoPor.name} ${fila.subidoPor.apellidos ?? ''}`
                        : '—'
                }}
            </template>
            <template #acciones="{ fila }">
                <Button
                    variant="ghost"
                    size="icon"
                    title="Eliminar"
                    @click="eliminar(fila)"
                >
                    <Trash2 class="size-4 text-destructive" />
                </Button>
            </template>
        </DataTable>
    </div>

    <MultimediaUploadDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :tipos="tipos"
    />
</template>
