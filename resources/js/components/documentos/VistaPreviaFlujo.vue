<script setup lang="ts">
import { Eye, Loader2 } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { useDocumentosProceso } from '@/composables/useDocumentosProceso';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import type { DocumentoVistaPrevia, FlujoVistaPrevia } from '@/types';

/**
 * «Vista previa» de los documentos que un flujo GENERA AL CONFIRMAR
 * (renovación, no renovación, baja, préstamo), dentro de su diálogo de
 * confirmación. El backend arma el registro en memoria con los datos del
 * formulario y responde el PDF real sin guardar nada; la regla del flujo no
 * cambia (se generan al confirmar). Si con esos datos el paso no genera
 * ningún documento (o quien mira no puede verlos), el botón no aparece.
 */
const props = defineProps<{
    flujo: FlujoVistaPrevia;
    id: number;
    /** Datos actuales del formulario (tipo_baja, fecha_efectiva, motivo, monto_autorizado…). */
    datos?: Record<string, string | number | null | undefined>;
    disabled?: boolean;
    /** Texto del botón (por defecto «Vista previa»). */
    etiqueta?: string;
}>();

const api = useDocumentosProceso();
const visor = useVistaPreviaPdf();
const documentos = ref<DocumentoVistaPrevia[]>([]);
let temporizador: ReturnType<typeof setTimeout> | null = null;
let consulta = 0;

async function cargarDocumentos() {
    const actual = ++consulta;

    try {
        const lista = await api.previaFlujo(
            props.flujo,
            props.id,
            props.datos ?? {},
        );

        if (actual === consulta) {
            documentos.value = lista;
        }
    } catch {
        // Sin documentos que ver (fuera de alcance, datos aún incompletos…): sin botón.
        if (actual === consulta) {
            documentos.value = [];
        }
    }
}

watch(
    () => [props.flujo, props.id, JSON.stringify(props.datos ?? {})],
    () => {
        if (temporizador) {
            clearTimeout(temporizador);
        }

        temporizador = setTimeout(() => void cargarDocumentos(), 300);
    },
    { immediate: true },
);

onBeforeUnmount(() => temporizador && clearTimeout(temporizador));

function ver() {
    void visor.abrir(documentos.value);
}
</script>

<template>
    <div
        v-if="documentos.length"
        class="flex flex-col gap-1.5 rounded-xl border border-[var(--mrl-borde)] bg-crema p-3"
    >
        <p class="text-xs text-[var(--mrl-texto-suave)]">
            Al confirmar se genera{{ documentos.length === 1 ? '' : 'n' }}:
            {{ documentos.map((d) => d.nombre).join(', ') }}.
        </p>
        <Button
            type="button"
            size="sm"
            variant="outline"
            class="self-start"
            :disabled="disabled || visor.cargando.value"
            @click="ver"
        >
            <Loader2 v-if="visor.cargando.value" class="size-4 animate-spin" />
            <Eye v-else class="size-4" />
            {{ etiqueta ?? 'Vista previa' }}
        </Button>
    </div>

    <DocumentPreviewDialog
        :open="visor.abierto.value"
        :archivos="visor.archivos.value"
        :indice="visor.indice.value"
        @update:open="visor.alCambiarAbierto"
        @update:indice="(i: number) => (visor.indice.value = i)"
    />
</template>
