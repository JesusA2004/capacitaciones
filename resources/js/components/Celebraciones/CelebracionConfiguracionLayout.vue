<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, ImageOff, RefreshCw } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';

/**
 * Pantalla de configuración de una tarjeta de celebración (Cumpleaños y
 * Aniversarios comparten diseño): encabezado compacto con "volver",
 * formulario de ancho razonable y la vista previa REAL (PNG que genera el
 * servidor con datos de ejemplo) a un lado en desktop / debajo en móvil.
 */
const props = defineProps<{
    titulo: string;
    volverUrl: string;
    vistaPreviaUrl: string;
    /** Cambia cuando algo guardado afecta la tarjeta: recarga la vista previa. */
    version: number;
}>();

const cargando = ref(true);
const error = ref(false);
const versionLocal = ref(props.version);

watch(
    () => props.version,
    (valor) => recargar(valor),
);

function recargar(valor = Date.now()) {
    cargando.value = true;
    error.value = false;
    versionLocal.value = valor;
}
</script>

<template>
    <div class="mx-auto flex w-full max-w-6xl min-w-0 flex-col gap-4 p-3 sm:p-4 lg:px-6">
        <div class="flex items-center gap-2">
            <Button as-child variant="ghost" size="icon-sm">
                <Link :href="volverUrl" aria-label="Volver"><ArrowLeft class="size-4" /></Link>
            </Button>
            <h1 class="text-lg font-semibold">{{ titulo }}</h1>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem] xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="flex min-w-0 flex-col gap-6">
                <slot />
            </div>

            <aside class="flex flex-col gap-2 lg:sticky lg:top-4" aria-label="Vista previa">
                <div class="flex items-center justify-between">
                    <p class="text-sm font-medium">Vista previa <span class="font-normal text-muted-foreground">(datos de ejemplo)</span></p>
                    <Button size="icon-sm" variant="ghost" aria-label="Actualizar vista previa" @click="recargar()">
                        <RefreshCw class="size-4" />
                    </Button>
                </div>
                <div class="relative mx-auto aspect-[4/5] w-full max-w-sm overflow-hidden rounded-xl border bg-muted/40">
                    <Skeleton v-if="cargando && !error" class="absolute inset-0" />
                    <div v-if="error" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-6 text-center text-sm text-muted-foreground">
                        <ImageOff class="size-8" />
                        No se pudo generar la vista previa.
                    </div>
                    <img
                        :key="versionLocal"
                        :src="`${vistaPreviaUrl}?v=${versionLocal}`"
                        alt="Vista previa de la tarjeta"
                        class="size-full object-contain"
                        :class="{ invisible: cargando || error }"
                        @load="cargando = false"
                        @error="
                            cargando = false;
                            error = true;
                        "
                    />
                </div>
                <p class="text-xs text-muted-foreground">Refleja lo guardado. Guarda para ver tus cambios.</p>
            </aside>
        </div>
    </div>
</template>
