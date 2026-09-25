<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Download, Eye, FileCheck2, FolderOpen, Search } from '@lucide/vue';
import { ref, watch } from 'vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import FormatosTabsNav from '@/components/Rh/FormatosTabsNav.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { dashboard } from '@/routes';
import { show as showExpediente } from '@/routes/rh/expedientes';
import { generados } from '@/routes/rh/formatos-oficiales';
import type { GeneracionFormatoItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    generaciones: RespuestaPaginada<GeneracionFormatoItem>;
    formatos: { id: number; nombre: string }[];
    filtros: { formato_id: number | null; busqueda: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formatos', href: '' },
            { title: 'Documentos generados', href: '' },
        ],
    },
});

const busqueda = ref(props.filtros.busqueda);
const formatoId = ref(props.filtros.formato_id ? String(props.filtros.formato_id) : '');
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch([busqueda, formatoId], () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => {
        router.get(
            generados.url(),
            { busqueda: busqueda.value || undefined, formato_id: formatoId.value || undefined },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }, 300);
});

const columnas = [
    { clave: 'formato', etiqueta: 'Documento' },
    { clave: 'persona', etiqueta: 'Para' },
    { clave: 'generado_en', etiqueta: 'Generado' },
    { clave: 'estado', etiqueta: 'Estado' },
];

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '—';
}
</script>

<template>
    <Head title="Documentos generados" />

    <div class="flex w-full min-w-0 flex-col gap-4 p-4 sm:p-6">
        <FormatosTabsNav activa="generados" />

        <div class="flex flex-col gap-2 sm:flex-row">
            <div class="relative w-full sm:max-w-xs">
                <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                <Input v-model="busqueda" placeholder="Buscar por persona…" class="pl-8" />
            </div>
            <NativeSelect v-model="formatoId" class="w-full sm:w-72">
                <option value="">Todos los formatos</option>
                <option v-for="f in formatos" :key="f.id" :value="String(f.id)">{{ f.nombre }}</option>
            </NativeSelect>
        </div>

        <DataTable :columnas="columnas" :datos="generaciones" mensaje-vacio="Todavía no se han generado documentos.">
            <template #celda-formato="{ fila }">
                <div class="flex min-w-0 flex-col">
                    <span class="font-medium">{{ fila.formato }}</span>
                    <span class="text-xs text-muted-foreground">
                        {{ fila.categoria }} · v{{ fila.version ?? '—' }}<template v-if="fila.solicitud_folio"> · {{ fila.solicitud_folio }}</template>
                    </span>
                </div>
            </template>
            <template #celda-persona="{ fila }">
                <span>{{ fila.persona ?? '—' }}</span>
                <span v-if="fila.tipo_persona === 'candidato'" class="ml-1 text-xs text-muted-foreground">(candidato)</span>
            </template>
            <template #celda-generado_en="{ fila }">
                <div class="flex flex-col text-sm">
                    <span>{{ fecha(fila.generado_en) }}</span>
                    <span class="text-xs text-muted-foreground">{{ fila.generado_por ?? '—' }}</span>
                </div>
            </template>
            <template #celda-estado="{ fila }">
                <div class="flex flex-wrap gap-1">
                    <Badge :variant="fila.estado === 'firmado' ? 'default' : 'outline'">
                        <FileCheck2 v-if="fila.estado === 'firmado'" class="size-3" />
                        {{ fila.estado === 'firmado' ? 'Firmado' : 'Generado' }}
                    </Badge>
                    <Badge v-if="fila.en_expediente" variant="outline">En expediente</Badge>
                </div>
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-1">
                    <Button as-child size="icon" variant="ghost" aria-label="Ver"><a :href="fila.ver_url" target="_blank"><Eye class="size-4" /></a></Button>
                    <Button as-child size="icon" variant="ghost" aria-label="Descargar"><a :href="fila.descargar_url"><Download class="size-4" /></a></Button>
                    <Button v-if="fila.colaborador_id" as-child size="icon" variant="ghost" aria-label="Expediente">
                        <a :href="showExpediente.url(fila.colaborador_id)"><FolderOpen class="size-4" /></a>
                    </Button>
                </div>
            </template>
        </DataTable>
    </div>
</template>
