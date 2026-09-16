<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { FileText, FileUp, Upload, UploadCloud, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { useAlertas } from '@/composables/useAlertas';
import { store as subirDocumento } from '@/routes/rh/expedientes/documentos';

const props = defineProps<{
    colaboradorId: number;
    tipoId: number;
    etiqueta: string;
}>();

const { mostrarExito, mostrarError } = useAlertas();

const inputEl = ref<HTMLInputElement | null>(null);
const arrastrando = ref(false);
const subiendo = ref(false);
const progreso = ref(0);
const archivoPendiente = ref<File | null>(null);
const previewUrl = ref<string | null>(null);

const esImagenPendiente = computed(
    () => archivoPendiente.value?.type.startsWith('image/') ?? false,
);
const esPdfPendiente = computed(
    () => archivoPendiente.value?.type === 'application/pdf',
);

function abrirSelector() {
    inputEl.value?.click();
}

function limpiarPreview() {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }

    previewUrl.value = null;
    archivoPendiente.value = null;
}

onBeforeUnmount(limpiarPreview);

function elegirArchivo(archivo: File) {
    limpiarPreview();
    archivoPendiente.value = archivo;

    if (archivo.type.startsWith('image/') || archivo.type === 'application/pdf') {
        previewUrl.value = URL.createObjectURL(archivo);
    }
}

function archivoSeleccionado(evento: Event) {
    const input = evento.target as HTMLInputElement;
    const archivo = input.files?.[0];

    if (archivo) {
        elegirArchivo(archivo);
    }
}

function soltar(evento: DragEvent) {
    arrastrando.value = false;
    const archivo = evento.dataTransfer?.files?.[0];

    if (archivo) {
        elegirArchivo(archivo);
    }
}

function cancelar() {
    limpiarPreview();

    if (inputEl.value) {
        inputEl.value.value = '';
    }
}

function confirmarSubida() {
    if (!archivoPendiente.value) {
        return;
    }

    const archivo = archivoPendiente.value;
    subiendo.value = true;
    progreso.value = 0;

    router.post(
        subirDocumento.url(props.colaboradorId),
        { document_type_id: props.tipoId, archivo },
        {
            forceFormData: true,
            preserveScroll: true,
            onProgress: (evento) => {
                progreso.value = evento?.percentage ?? 0;
            },
            onSuccess: () =>
                mostrarExito('Documento cargado. Queda en revisión.'),
            onError: () => mostrarError('No fue posible cargar el documento.'),
            onFinish: () => {
                subiendo.value = false;
                progreso.value = 0;
                limpiarPreview();

                if (inputEl.value) {
                    inputEl.value.value = '';
                }
            },
        },
    );
}
</script>

<template>
    <div>
        <input
            ref="inputEl"
            type="file"
            accept=".pdf,.jpg,.jpeg,.png"
            class="hidden"
            @change="archivoSeleccionado"
        />

        <div
            v-if="subiendo"
            class="flex flex-col gap-2 rounded-xl border border-dashed border-primary/50 bg-primary/5 p-3"
        >
            <p class="flex items-center gap-2 text-xs font-medium text-primary">
                <FileUp class="size-3.5 animate-pulse" />
                Subiendo... {{ progreso }}%
            </p>
            <Progress :model-value="progreso" class="h-1.5" />
        </div>

        <div
            v-else-if="archivoPendiente"
            class="flex flex-col gap-2 rounded-xl border border-primary/40 bg-primary/5 p-3"
        >
            <div class="flex items-center gap-2">
                <img
                    v-if="esImagenPendiente && previewUrl"
                    :src="previewUrl"
                    alt=""
                    class="size-12 shrink-0 rounded-lg border border-border/60 object-cover"
                />
                <iframe
                    v-else-if="esPdfPendiente && previewUrl"
                    :src="previewUrl"
                    title="Vista previa del PDF"
                    class="h-16 w-14 shrink-0 rounded-lg border border-border/60"
                />
                <FileText
                    v-else
                    class="size-8 shrink-0 text-muted-foreground"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-xs font-medium">
                        {{ archivoPendiente.name }}
                    </p>
                    <p class="text-[11px] text-muted-foreground">
                        {{ (archivoPendiente.size / 1024).toFixed(0) }} KB ·
                        listo para subir
                    </p>
                </div>
                <button
                    type="button"
                    class="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                    title="Quitar"
                    @click="cancelar"
                >
                    <X class="size-3.5" />
                </button>
            </div>

            <Button size="sm" class="w-full" @click="confirmarSubida">
                <Upload class="size-3.5" />
                Subir
            </Button>
        </div>

        <button
            v-else
            type="button"
            class="flex w-full flex-col items-center gap-1 rounded-xl border-2 border-dashed p-3 text-center transition-colors"
            :class="
                arrastrando
                    ? 'border-primary bg-primary/5'
                    : 'border-border/60 hover:border-primary/40 hover:bg-accent/40'
            "
            @click="abrirSelector"
            @dragover.prevent="arrastrando = true"
            @dragleave.prevent="arrastrando = false"
            @drop.prevent="soltar"
        >
            <UploadCloud class="size-5 text-muted-foreground" />
            <span class="text-xs font-medium">{{ etiqueta }}</span>
            <span class="text-[11px] text-muted-foreground"
                >Arrastra un archivo o haz clic · PDF, JPG o PNG</span
            >
        </button>
    </div>
</template>
