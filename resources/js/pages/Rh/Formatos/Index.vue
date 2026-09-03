<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    Download,
    FileSpreadsheet,
    FileStack,
    FileText,
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import {
    descargar,
    destroy,
    exportarExcel,
    exportarPdf,
    index,
    store,
} from '@/routes/rh/formatos';
import type {
    DocumentoGeneradoItem,
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
    plantillasDisponibles: { id: number; nombre: string; tipo: string }[];
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
        breadcrumbs: [{ title: 'Formatos', href: '' }],
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
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const form = useForm({
    document_template_id: '',
    tipo_sujeto: 'colaborador' as 'colaborador' | 'candidato',
    sujeto_id: '',
});

function generar() {
    form.transform((datos) => ({
        ...datos,
        document_template_id: Number(datos.document_template_id),
        sujeto_id: Number(datos.sujeto_id),
    })).post(store.url(), {
        preserveScroll: true,
        onSuccess: () => form.reset('sujeto_id'),
        onError: () => mostrarError('No fue posible generar el documento.'),
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
    <Head title="Formatos" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Formatos"
            descripcion="Genera documentos precargados a partir de una plantilla."
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

        <form
            class="grid gap-4 rounded-2xl border border-border/60 bg-card p-4 sm:grid-cols-4 sm:items-end"
            @submit.prevent="generar"
        >
            <div class="grid gap-2 sm:col-span-2">
                <label class="text-sm font-medium">Plantilla</label>
                <Select v-model="form.document_template_id">
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Selecciona una plantilla" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="opcion in plantillasDisponibles"
                            :key="opcion.id"
                            :value="String(opcion.id)"
                            >{{ opcion.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <label class="text-sm font-medium">Para</label>
                <Select v-model="form.tipo_sujeto">
                    <SelectTrigger class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="colaborador">Colaborador</SelectItem>
                        <SelectItem value="candidato">Candidato</SelectItem>
                    </SelectContent>
                </Select>
            </div>

            <div class="grid gap-2">
                <label class="text-sm font-medium">{{
                    form.tipo_sujeto === 'colaborador'
                        ? 'Colaborador'
                        : 'Candidato'
                }}</label>
                <Select v-model="form.sujeto_id">
                    <SelectTrigger class="w-full">
                        <SelectValue placeholder="Selecciona..." />
                    </SelectTrigger>
                    <SelectContent>
                        <template v-if="form.tipo_sujeto === 'colaborador'">
                            <SelectItem
                                v-for="opcion in colaboradoresDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.name }}
                                {{ opcion.apellidos }}</SelectItem
                            >
                        </template>
                        <template v-else>
                            <SelectItem
                                v-for="opcion in candidatosDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}
                                {{ opcion.apellidos }}</SelectItem
                            >
                        </template>
                    </SelectContent>
                </Select>
            </div>

            <div class="sm:col-span-4">
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Generar documento
                </Button>
            </div>
        </form>

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
                        ><Download class="size-4" /> Descargar</a
                    >
                    <button
                        type="button"
                        class="text-muted-foreground hover:text-destructive"
                        @click="eliminar(fila)"
                    >
                        <Trash2 class="size-4" />
                    </button>
                </div>
            </template>
        </DataTable>
    </div>
</template>
