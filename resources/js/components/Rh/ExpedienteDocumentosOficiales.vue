<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Download, Eye, FileSignature, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import FormatoOficialGenerarDialog from '@/components/Rh/FormatoOficialGenerarDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/native-select';
import type { FormatoOficialItem } from '@/types';

/**
 * Pestaña Documentos del expediente (vista RH): documentos generados desde
 * plantillas oficiales para esta persona y botón "Generar documento" con
 * el colaborador ya fijo (docs/FORMATOS_OFICIALES.md).
 */
const props = defineProps<{
    colaborador: { id: number; nombre: string };
    documentos: {
        id: number;
        formato: string;
        categoria: string;
        version: number | null;
        solicitud_folio: string | null;
        generado_por: string | null;
        generado_en: string | null;
        estado: string;
        ver_url: string;
        descargar_url: string;
    }[];
    formatos: FormatoOficialItem[];
    puedeDescargar: boolean;
}>();

const formatoId = ref('');
const dialogo = ref(false);
const formatoElegido = ref<FormatoOficialItem | null>(null);

function abrir() {
    formatoElegido.value = props.formatos.find((f) => String(f.id) === formatoId.value) ?? null;
    dialogo.value = formatoElegido.value !== null;
}

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}
</script>

<template>
    <section class="rounded-2xl border p-4">
        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <h3 class="flex items-center gap-2 font-medium"><FileSignature class="size-4 text-muted-foreground" />Documentos oficiales</h3>
            <div v-if="formatos.length > 0" class="flex gap-2">
                <NativeSelect v-model="formatoId" class="w-full sm:w-64">
                    <option value="">Elige un formato…</option>
                    <option v-for="f in formatos" :key="f.id" :value="String(f.id)">{{ f.nombre }}</option>
                </NativeSelect>
                <Button :disabled="!formatoId" @click="abrir">
                    <Sparkles class="size-4" />
                    Generar
                </Button>
            </div>
        </div>

        <p v-if="documentos.length === 0" class="text-sm text-muted-foreground">Aún no se han generado documentos para esta persona.</p>
        <ul v-else class="divide-y">
            <li v-for="d in documentos" :key="d.id" class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                <div class="min-w-0">
                    <p class="font-medium">{{ d.formato }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ d.categoria }} · v{{ d.version ?? '—' }} · {{ fecha(d.generado_en) }} · {{ d.generado_por ?? 'sistema' }}<template v-if="d.solicitud_folio"> · {{ d.solicitud_folio }}</template>
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <Badge :variant="d.estado === 'firmado' ? 'default' : 'outline'">{{ d.estado === 'firmado' ? 'Firmado' : 'Generado' }}</Badge>
                    <Button as-child size="icon" variant="ghost" aria-label="Ver"><a :href="d.ver_url" target="_blank"><Eye class="size-4" /></a></Button>
                    <Button v-if="puedeDescargar" as-child size="icon" variant="ghost" aria-label="Descargar"><a :href="d.descargar_url"><Download class="size-4" /></a></Button>
                </div>
            </li>
        </ul>
    </section>

    <FormatoOficialGenerarDialog
        v-if="formatoElegido"
        v-model:open="dialogo"
        :formato="formatoElegido"
        :sujeto-fijo="{ tipo: 'colaborador', id: colaborador.id, nombre: colaborador.nombre }"
        :puede-descargar="puedeDescargar"
        @generado="router.reload({ only: ['documentosOficiales'] })"
    />
</template>
