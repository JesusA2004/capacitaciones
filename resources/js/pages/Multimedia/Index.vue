<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AudioLines,
    FileText,
    Image as ImageIcon,
    Loader2,
    Plus,
    Trash2,
    Video,
} from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import type { Component } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import MultimediaUploadDialog from '@/components/Multimedia/MultimediaUploadDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index as indexCapacitacion } from '@/routes/capacitacion';
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
            { title: 'Capacitación', href: indexCapacitacion() },
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
    { clave: 'subido_por', etiqueta: 'Cargado por' },
];

const dialogAbierto = ref(false);

const ICONO_TIPO: Record<string, { icono: Component; clase: string }> = {
    video: { icono: Video, clase: 'bg-info-soft text-primary' },
    audio: { icono: AudioLines, clase: 'bg-menta text-esmeralda' },
    documento: { icono: FileText, clase: 'bg-crema text-bronce' },
    imagen: { icono: ImageIcon, clase: 'bg-salvia text-primary' },
};

const ESTADO: Record<string, { texto: string; clase: string }> = {
    cargando: { texto: 'Cargando', clase: 'bg-info-soft text-primary' },
    pendiente: {
        texto: 'En cola',
        clase: 'bg-warning-soft text-bronce',
    },
    procesando: {
        texto: 'Procesando',
        clase: 'bg-warning-soft text-bronce',
    },
    disponible: { texto: 'Disponible', clase: 'bg-success-soft text-primary' },
    error: { texto: 'Error', clase: 'bg-danger-soft text-destructive' },
};

function etiquetaTipo(valor: string): string {
    return props.tipos.find((t) => t.value === valor)?.etiqueta ?? valor;
}

function tamano(bytes: number | null): string | null {
    if (!bytes) {
        return null;
    }

    const mb = bytes / 1_048_576;

    return mb >= 1
        ? `${mb.toLocaleString('es-MX', { maximumFractionDigits: 1 })} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function duracion(segundos: number | null): string | null {
    if (!segundos) {
        return null;
    }

    const m = Math.floor(segundos / 60);
    const s = Math.round(segundos % 60);

    return `${m}:${String(s).padStart(2, '0')} min`;
}

function filtrarTipo(valor: string) {
    filtros.tipo = filtros.tipo === valor ? '' : valor;
    aplicar();
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

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Biblioteca multimedia"
            descripcion="Videos, documentos e imágenes usados en las lecciones."
            :icono="Video"
        >
            <Button @click="dialogAbierto = true">
                <Plus class="size-4" />
                Cargar archivo
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
        >
            <div
                class="flex flex-wrap gap-1 rounded-xl border border-border/60 bg-crema/40 p-1"
                role="group"
                aria-label="Filtrar por tipo"
            >
                <button
                    type="button"
                    class="presionable rounded-lg px-3 py-1.5 text-xs font-medium transicion-ui"
                    :class="
                        filtros.tipo === ''
                            ? 'bg-card text-primary shadow-sm'
                            : 'text-muted-foreground hover:bg-card/60'
                    "
                    :aria-pressed="filtros.tipo === ''"
                    @click="filtrarTipo('')"
                >
                    Todos
                </button>
                <button
                    v-for="opcion in tipos"
                    :key="opcion.value"
                    type="button"
                    class="flex presionable items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-medium transicion-ui"
                    :class="
                        filtros.tipo === opcion.value
                            ? 'bg-card text-primary shadow-sm'
                            : 'text-muted-foreground hover:bg-card/60'
                    "
                    :aria-pressed="filtros.tipo === opcion.value"
                    @click="filtrarTipo(opcion.value)"
                >
                    <component
                        :is="ICONO_TIPO[opcion.value]?.icono ?? FileText"
                        class="size-3.5"
                    />
                    {{ opcion.etiqueta }}
                </button>
            </div>
        </TableFilters>

        <DataTable
            :columnas="columnas"
            :datos="recursos"
            mensaje-vacio="Todavía no se ha cargado ningún archivo. Carga videos o documentos para usarlos en las lecciones."
        >
            <template #celda-nombre_original="{ fila }">
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                        :class="
                            ICONO_TIPO[fila.tipo]?.clase ??
                            'bg-muted text-muted-foreground'
                        "
                        aria-hidden="true"
                    >
                        <component
                            :is="ICONO_TIPO[fila.tipo]?.icono ?? FileText"
                            class="size-4"
                        />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-medium">
                            {{ fila.nombre_original }}
                        </p>
                        <p class="text-xs text-muted-foreground tabular-nums">
                            {{
                                [
                                    tamano(fila.tamano_bytes),
                                    duracion(fila.duracion_segundos),
                                    formatearFecha(fila.created_at),
                                ]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>
                    </div>
                </div>
            </template>
            <template #celda-tipo="{ fila }">
                <span class="text-muted-foreground">{{
                    etiquetaTipo(fila.tipo)
                }}</span>
            </template>
            <template #celda-estado="{ fila }">
                <Badge
                    variant="secondary"
                    :class="
                        ESTADO[fila.estado]?.clase ??
                        'bg-muted text-muted-foreground'
                    "
                >
                    <Loader2
                        v-if="
                            fila.estado === 'procesando' ||
                            fila.estado === 'cargando'
                        "
                        class="size-3 animate-spin"
                    />
                    {{ ESTADO[fila.estado]?.texto ?? fila.estado }}
                </Badge>
                <p
                    v-if="fila.estado === 'error' && fila.error_procesamiento"
                    class="mt-1 text-xs text-destructive"
                >
                    {{ fila.error_procesamiento }}
                </p>
            </template>
            <template #celda-subido_por="{ fila }">
                {{
                    fila.subido_por
                        ? `${fila.subido_por.name} ${fila.subido_por.apellidos ?? ''}`
                        : '—'
                }}
            </template>
            <template #acciones="{ fila }">
                <Button
                    variant="ghost"
                    size="icon"
                    title="Eliminar"
                    aria-label="Eliminar archivo"
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
