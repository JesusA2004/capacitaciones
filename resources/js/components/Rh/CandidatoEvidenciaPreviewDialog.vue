<script setup lang="ts">
import { ExternalLink, FileWarning } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { descargar as descargarEvidencia } from '@/routes/rh/candidatos/evidencias';

/**
 * Preview embebido de una evidencia de reclutamiento (psicométricos /
 * socioeconómico): nunca abre otra página por default (CLAUDE.md §7-8).
 * Imagen y PDF en línea, video con reproductor embebido (sin autoplay,
 * HTTP Range real vía CandidatoController::descargarEvidencia).
 */
const props = defineProps<{
    open: boolean;
    candidatoId: number;
    evidenciaId: number;
    nombreArchivo: string;
    tipo: string;
    mime: string | null;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const url = computed(() =>
    descargarEvidencia.url({
        candidato: props.candidatoId,
        evidencia: props.evidenciaId,
    }),
);
const esImagen = computed(
    () => props.tipo === 'fotografia' || (props.mime?.startsWith('image/') ?? false),
);
const esVideo = computed(
    () => props.tipo === 'video' || (props.mime?.startsWith('video/') ?? false),
);
const esPdf = computed(() => props.mime === 'application/pdf');
</script>

<template>
    <Sheet :open="open" @update:open="(v) => emit('update:open', v)">
        <SheetContent
            side="right"
            class="flex w-full flex-col gap-0 p-0 sm:max-w-xl lg:max-w-2xl"
        >
            <SheetHeader class="border-b border-border/60 px-4 py-3">
                <SheetTitle class="truncate pr-8 text-sm">{{
                    nombreArchivo
                }}</SheetTitle>
            </SheetHeader>

            <div class="min-h-0 flex-1 overflow-auto bg-muted/30">
                <img
                    v-if="esImagen"
                    :src="url"
                    :alt="nombreArchivo"
                    class="mx-auto max-w-full object-contain"
                />
                <video
                    v-else-if="esVideo"
                    :src="url"
                    controls
                    preload="metadata"
                    class="mx-auto max-h-[80vh] w-full bg-black"
                />
                <iframe
                    v-else-if="esPdf"
                    :src="url"
                    :title="nombreArchivo"
                    class="h-full min-h-[80vh] w-full"
                />
                <div
                    v-else
                    class="flex h-[50vh] flex-col items-center justify-center gap-3 p-6 text-center text-sm text-muted-foreground"
                >
                    <FileWarning class="size-8" />
                    <p>
                        No hay vista previa disponible para este tipo de
                        archivo.
                    </p>
                </div>
            </div>

            <div class="flex justify-end border-t border-border/60 px-4 py-3">
                <Button as-child variant="outline" size="sm">
                    <a :href="url" target="_blank" rel="noopener">
                        <ExternalLink class="size-3.5" />
                        Abrir en pestaña nueva
                    </a>
                </Button>
            </div>
        </SheetContent>
    </Sheet>
</template>
