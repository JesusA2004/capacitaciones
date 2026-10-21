<script setup lang="ts">
import { FileText } from '@lucide/vue';
import { onBeforeUnmount, ref, watch } from 'vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
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
        class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-crema p-3 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex min-w-0 flex-col gap-1.5">
            <p
                class="text-xs font-semibold tracking-wide text-bronce uppercase"
            >
                Se genera{{ documentos.length === 1 ? '' : 'n' }} al confirmar
            </p>
            <ul class="flex flex-wrap gap-1.5">
                <li
                    v-for="d in documentos"
                    :key="d.clave"
                    class="inline-flex max-w-full items-center gap-1.5 rounded-full bg-card px-2.5 py-1 text-xs font-medium"
                >
                    <FileText class="size-3.5 shrink-0 text-bronce" />
                    <span class="truncate">{{ d.nombre }}</span>
                </li>
            </ul>
        </div>
        <BotonVistaPrevia
            class="w-full shrink-0 sm:w-auto"
            :texto="etiqueta ?? 'Vista previa'"
            :cargando="visor.cargando.value"
            :deshabilitado="disabled"
            @click="ver"
        />
    </div>

    <DocumentPreviewDialog
        :open="visor.abierto.value"
        :archivos="visor.archivos.value"
        :indice="visor.indice.value"
        :cargando="visor.cargando.value"
        :titulo-carga="visor.tituloCarga.value"
        recargable
        @update:open="visor.alCambiarAbierto"
        @recargar="visor.recargar"
        @update:indice="(i: number) => (visor.indice.value = i)"
    />
</template>
