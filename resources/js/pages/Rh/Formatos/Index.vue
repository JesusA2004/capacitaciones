<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, FileStack, Trash2 } from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { descargar, destroy, store } from '@/routes/rh/formatos';
import type { DocumentoGeneradoItem, RespuestaPaginada } from '@/types';

defineProps<{
    documentos: RespuestaPaginada<DocumentoGeneradoItem>;
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
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Formatos', href: '' }],
    },
});

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
        />

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
