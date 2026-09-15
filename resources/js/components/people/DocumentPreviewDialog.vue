<script setup lang="ts">
import { Download, ExternalLink, FileWarning } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Visor de documentos del NAS (sección 15 del encargo "cierre definitivo"):
 * nunca recibe una ruta física ni una URL directa al NAS — solo la URL de un
 * endpoint protegido que ya sirve el archivo con `Content-Disposition:
 * inline` y el Content-Type real (autorización siempre en el backend, ver
 * CLAUDE.md). Este componente solo decide CÓMO mostrarlo (iframe/img/aviso),
 * nunca si el usuario puede verlo.
 */
const props = defineProps<{
    open: boolean;
    /** URL protegida que sirve el archivo inline (p. ej. .../descargar o .../previsualizar). */
    previewUrl: string | null;
    /** URL para forzar descarga (attachment); si se omite, usa previewUrl. */
    downloadUrl?: string | null;
    nombre: string;
    tipo?: string | null;
    version?: number | null;
    estado?: string | null;
    subidoPor?: string | null;
    fecha?: string | null;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const EXTENSIONES_IMAGEN = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

const extension = computed(() => {
    const fuente = props.tipo ?? props.nombre;

    return fuente.split('.').pop()?.toLowerCase() ?? '';
});

const esPdf = computed(() => extension.value === 'pdf');
const esImagen = computed(() => EXTENSIONES_IMAGEN.includes(extension.value));
const esPrevisualizable = computed(() => esPdf.value || esImagen.value);

const urlDescarga = computed(() => props.downloadUrl ?? props.previewUrl);
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent
            class="flex h-[90vh] w-[calc(100vw-2rem)] max-w-none flex-col gap-3 sm:h-[90vh] sm:w-[90vw] sm:max-w-[1400px]"
        >
            <DialogHeader>
                <DialogTitle class="truncate">{{ nombre }}</DialogTitle>
                <DialogDescription>
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span v-if="version">Versión {{ version }}</span>
                        <span v-if="estado" class="capitalize">{{
                            estado.replace(/_/g, ' ')
                        }}</span>
                        <span v-if="subidoPor">Subido por {{ subidoPor }}</span>
                        <span v-if="fecha">{{ fecha }}</span>
                    </span>
                </DialogDescription>
            </DialogHeader>

            <div
                class="min-h-0 flex-1 overflow-hidden rounded-xl border border-border/60 bg-muted/30"
            >
                <iframe
                    v-if="esPdf && previewUrl"
                    :src="previewUrl"
                    class="size-full"
                    title="Vista previa del documento"
                />
                <div
                    v-else-if="esImagen && previewUrl"
                    class="flex size-full items-center justify-center overflow-auto p-4"
                >
                    <img
                        :src="previewUrl"
                        alt=""
                        class="max-h-full max-w-full object-contain"
                    />
                </div>
                <div
                    v-else
                    class="flex size-full flex-col items-center justify-center gap-2 p-8 text-center text-muted-foreground"
                >
                    <FileWarning class="size-10" />
                    <p class="text-sm">
                        No se puede previsualizar este tipo de archivo.
                    </p>
                </div>
            </div>

            <DialogFooter class="flex-row flex-wrap items-center justify-end gap-2">
                <Button as-child variant="outline" :disabled="!urlDescarga">
                    <a :href="urlDescarga ?? '#'">
                        <Download class="size-4" />
                        Descargar
                    </a>
                </Button>
                <Button
                    as-child
                    variant="outline"
                    :disabled="!previewUrl || !esPrevisualizable"
                >
                    <a :href="previewUrl ?? '#'" target="_blank" rel="noopener">
                        <ExternalLink class="size-4" />
                        Abrir en otra pestaña
                    </a>
                </Button>
                <Button variant="secondary" @click="emit('update:open', false)">
                    Cerrar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
