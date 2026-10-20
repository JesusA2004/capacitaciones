<script setup lang="ts">
import { Spinner } from '@/components/ui/spinner';

/**
 * Hoja de documento «fantasma» con brillo animado + spinner + texto corto.
 * Es el único indicador de carga de las vistas previas (DocumentPreviewDialog
 * lo muestra mientras el PDF/imagen se genera o llega), para que en toda la
 * app se vea igual que algo se está preparando.
 */
withDefaults(
    defineProps<{
        texto?: string;
        detalle?: string | null;
    }>(),
    { texto: 'Generando vista previa…', detalle: null },
);
</script>

<template>
    <div
        class="flex w-full max-w-sm flex-col items-center gap-5"
        role="status"
        aria-live="polite"
    >
        <div
            class="relative flex aspect-[3/4] w-44 flex-col gap-2.5 overflow-hidden rounded-2xl border border-border/70 bg-card p-5 shadow-md sm:w-52"
            aria-hidden="true"
        >
            <span class="h-3 w-2/3 rounded-full bg-crema" />
            <span class="h-2 w-1/2 rounded-full bg-crema/70" />
            <span class="mt-2 h-2 w-full rounded-full bg-crema/70" />
            <span class="h-2 w-11/12 rounded-full bg-crema/70" />
            <span class="h-2 w-full rounded-full bg-crema/70" />
            <span class="h-2 w-4/5 rounded-full bg-crema/70" />
            <span class="mt-2 flex-1 rounded-xl bg-crema/50" />
            <span class="flex gap-3">
                <span class="h-2 flex-1 rounded-full bg-crema/70" />
                <span class="h-2 flex-1 rounded-full bg-crema/70" />
            </span>
            <span class="absolute inset-0 brillo-carga" />
        </div>
        <div class="flex flex-col items-center gap-1 text-center">
            <p
                class="flex items-center gap-2 text-base font-semibold text-primary"
            >
                <Spinner class="size-5" />
                {{ texto }}
            </p>
            <p v-if="detalle" class="text-sm text-muted-foreground">
                {{ detalle }}
            </p>
        </div>
    </div>
</template>
