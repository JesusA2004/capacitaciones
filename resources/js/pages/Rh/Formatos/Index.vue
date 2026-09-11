<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Clock,
    Download,
    FileSpreadsheet,
    FileStack,
    FileText,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import FormatoGenerarDialog from '@/components/Rh/FormatoGenerarDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { descargar, descargarPdf, destroy } from '@/routes/rh/formatos';
import {
    exportarExcel,
    exportarPdf,
    index,
} from '@/routes/rh/formatos/catalogo';
import { index as indexPlantillas } from '@/routes/rh/plantillas';
import type {
    DocumentoGeneradoItem,
    FormatoCatalogoItem,
    OpcionEnum,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    documentos: RespuestaPaginada<DocumentoGeneradoItem>;
    filtros: {
        tipo?: string;
        status?: string;
        generated_by?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    plantillasDisponibles: FormatoCatalogoItem[];
    colaboradoresDisponibles: {
        id: number;
        name: string;
        apellidos: string | null;
    }[];
    candidatosDisponibles: {
        id: number;
        nombre: string;
        apellidos: string | null;
    }[];
    responsablesDisponibles: {
        id: number;
        name: string;
        apellidos: string | null;
    }[];
    tipos: OpcionEnum[];
    estados: OpcionEnum[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Plantillas avanzadas', href: indexPlantillas() },
            { title: 'Documentos generados', href: '' },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        tipo: props.filtros.tipo ?? '',
        status: props.filtros.status ?? '',
        generated_by: props.filtros.generated_by ?? '',
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
const { confirmarEliminacion, mostrarExito } = useAlertas();

const plantillaSeleccionada = ref<FormatoCatalogoItem | null>(null);
const dialogGenerarAbierto = ref(false);

function abrirGenerar(plantilla: FormatoCatalogoItem) {
    plantillaSeleccionada.value = plantilla;
    dialogGenerarAbierto.value = true;
}

function formatearFecha(fecha: string | null): string {
    if (!fecha) {
        return 'Nunca generado';
    }

    return new Date(fecha).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const columnas: ColumnaDataTable[] = [
    { clave: 'generated_name', etiqueta: 'Documento' },
    { clave: 'plantilla', etiqueta: 'Plantilla' },
    { clave: 'sujeto', etiqueta: 'Para' },
    { clave: 'status', etiqueta: 'Estado' },
];

async function eliminar(documento: DocumentoGeneradoItem) {
    const confirmado = await confirmarEliminacion(
        `«${documento.generated_name}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(documento.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Documento eliminado.'),
    });
}
</script>

<template>
    <Head title="Documentos generados (avanzado)" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Documentos generados (avanzado)"
            descripcion="Genera documentos libres a partir de una plantilla DOCX editable. Para los formatos oficiales fijos de MR. LANA, usa el módulo «Formatos»."
            :icono="FileStack"
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
        </CrudPageHeader>

        <!-- Catalogo de formatos disponibles -->
        <div v-if="plantillasDisponibles.length === 0">
            <CrudEmptyState
                :icono="FileStack"
                titulo="Sin plantillas activas"
                descripcion="Sube una plantilla desde Plantillas para poder generar formatos a partir de ella."
            />
        </div>
        <div
            v-else
            class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3"
        >
            <Card
                v-for="plantilla in plantillasDisponibles"
                :key="plantilla.id"
                class="group flex flex-col gap-3 transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg"
            >
                <CardHeader class="pb-0">
                    <div class="flex items-start justify-between gap-2">
                        <CardTitle class="text-base">{{ plantilla.nombre }}</CardTitle>
                        <Badge variant="outline" class="shrink-0 text-xs">{{ plantilla.tipo_etiqueta }}</Badge>
                    </div>
                    <CardDescription>
                        {{ plantilla.descripcion ?? 'Sin descripción registrada.' }}
                    </CardDescription>
                </CardHeader>
                <CardContent class="flex flex-1 flex-col gap-3">
                    <div v-if="plantilla.variables.length" class="flex flex-wrap gap-1">
                        <Badge
                            v-for="variable in plantilla.variables.slice(0, 5)"
                            :key="variable"
                            variant="secondary"
                            class="text-[10px] font-normal"
                        >
                            {{ variable.replaceAll('_', ' ') }}
                        </Badge>
                        <Badge
                            v-if="plantilla.variables.length > 5"
                            variant="secondary"
                            class="text-[10px] font-normal"
                        >
                            +{{ plantilla.variables.length - 5 }}
                        </Badge>
                    </div>

                    <div class="mt-auto flex items-center justify-between text-xs text-muted-foreground">
                        <span class="inline-flex items-center gap-1">
                            <Clock class="size-3.5" />
                            {{ formatearFecha(plantilla.ultimo_uso) }}
                        </span>
                        <span>{{ plantilla.veces_generado }} generado(s)</span>
                    </div>

                    <Button size="sm" class="w-full" @click="abrirGenerar(plantilla)">
                        <Sparkles class="size-4" />
                        Generar
                    </Button>
                </CardContent>
            </Card>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre de documento..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
            />

            <Select
                :model-value="filtros.status"
                @update:model-value="
                    (v) => {
                        filtros.status = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todos los estados"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in estados"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Tipo de formato, responsable y fecha de generación."
                :contador-activos="
                    [
                        filtros.tipo,
                        filtros.generated_by,
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
                    <Label>Tipo de formato</Label>
                    <Select
                        :model-value="filtros.tipo"
                        @update:model-value="
                            (v) => (filtros.tipo = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in tipos"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Generado por</Label>
                    <Select
                        :model-value="filtros.generated_by"
                        @update:model-value="
                            (v) => (filtros.generated_by = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in responsablesDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.name }}
                                {{ opcion.apellidos }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Generado desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Generado hasta</Label>
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

        <div>
            <h3 class="text-sm font-semibold text-muted-foreground">
                Historial de documentos generados
            </h3>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="documentos"
            mensaje-vacio="Todavía no se ha generado ningún documento."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="FileStack"
                    titulo="Sin documentos generados"
                    descripcion="Selecciona una plantilla y un colaborador o candidato para generar el primero."
                />
            </template>

            <template #celda-plantilla="{ fila }">
                {{ fila.plantilla?.nombre ?? '—' }}
            </template>
            <template #celda-sujeto="{ fila }">
                <span v-if="fila.usuario"
                    >{{ fila.usuario.name }} {{ fila.usuario.apellidos }}</span
                >
                <span v-else-if="fila.candidato"
                    >{{ fila.candidato.nombre }}
                    {{ fila.candidato.apellidos }}</span
                >
                <span v-else>—</span>
            </template>
            <template #celda-status="{ fila }">
                <EstadoBadge :estado="fila.status" />
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-2">
                    <a
                        :href="descargar.url(fila.id)"
                        class="inline-flex items-center gap-1 text-sm text-[var(--brand-primary)] hover:underline"
                        title="Descargar Word"
                        ><Download class="size-4" /> Word</a
                    >
                    <a
                        :href="descargarPdf.url(fila.id)"
                        class="inline-flex items-center gap-1 text-sm text-[var(--brand-primary)] hover:underline"
                        title="Descargar PDF"
                        ><FileText class="size-4" /> PDF</a
                    >
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-destructive"
                        title="Eliminar"
                        @click="eliminar(fila)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </template>
        </DataTable>
    </div>

    <FormatoGenerarDialog
        v-if="dialogGenerarAbierto && plantillaSeleccionada"
        v-model:open="dialogGenerarAbierto"
        :plantilla="plantillaSeleccionada"
        :colaboradores-disponibles="colaboradoresDisponibles"
        :candidatos-disponibles="candidatosDisponibles"
        :key="plantillaSeleccionada.id"
    />
</template>
