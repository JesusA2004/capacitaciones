<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import EditorDatosDocumento from '@/components/documentos/EditorDatosDocumento.vue';
import type { PanelDocumentoSalida } from '@/types';

/**
 * Corrige un documento de salida antes de firmarlo: el editor común, apuntando a la
 * baja. Reglas: BajaDocumentoSalidaService.
 */
const abierto = defineModel<boolean>('open', { required: true });
defineProps<{ panel: PanelDocumentoSalida }>();

function alEmitir(creado: boolean, mensaje: string) {
    toast.success(mensaje);

    if (creado) {
        router.reload();
    }
}
</script>

<template>
    <EditorDatosDocumento
        v-model:open="abierto"
        tipo="salida"
        :id="panel.baja_id"
        :titulo="panel.documento?.etiqueta"
        @emitido="alEmitir"
    />
</template>
