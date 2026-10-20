<script setup lang="ts">
import { useIntersectionObserver } from '@vueuse/core';
import { ref, watch } from 'vue';

/**
 * Miniatura de UNA hoja del PDF histórico, dibujada con pdf.js (el mismo
 * documento abierto una sola vez por usePdfDocumento) y solo cuando entra
 * en pantalla: un PDF de 200 hojas no se dibuja entero al abrir el editor.
 * Una vez dibujada se conserva (el canvas es la caché).
 */
const props = defineProps<{
    numero: number;
    /** Ancho en px CSS de la miniatura. */
    ancho: number;
    /** Proporción alto/ancho de la hoja (A4 vertical ≈ 1.414). */
    proporcion: number;
    dibujar: (
        numero: number,
        lienzo: HTMLCanvasElement,
        pxPorMm: number,
    ) => Promise<void>;
    anchoMm: number;
    listo: boolean;
}>();

const caja = ref<HTMLElement | null>(null);
const lienzo = ref<HTMLCanvasElement | null>(null);
const visible = ref(false);
const dibujada = ref(false);
const fallo = ref(false);

useIntersectionObserver(
    caja,
    ([entrada]) => {
        if (entrada?.isIntersecting) {
            visible.value = true;
        }
    },
    { rootMargin: '400px' },
);

async function pintar(): Promise<void> {
    if (!props.listo || !visible.value || !lienzo.value || props.anchoMm <= 0) {
        return;
    }

    try {
        await props.dibujar(
            props.numero,
            lienzo.value,
            props.ancho / props.anchoMm,
        );
        dibujada.value = true;
        fallo.value = false;
    } catch {
        fallo.value = true;
    }
}

watch(
    () => [props.listo, visible.value, props.ancho] as const,
    () => void pintar(),
    { immediate: true },
);
</script>

<template>
    <div
        ref="caja"
        class="relative overflow-hidden rounded-md bg-white shadow-xs"
        :style="{
            width: `${ancho}px`,
            height: `${Math.round(ancho * proporcion)}px`,
        }"
    >
        <canvas
            ref="lienzo"
            class="block"
            :class="dibujada ? 'opacity-100' : 'opacity-0'"
            aria-hidden="true"
        />
        <span
            v-if="!dibujada"
            class="absolute inset-0 flex items-center justify-center text-xs text-muted-foreground"
        >
            {{ fallo ? 'Sin vista' : `Hoja ${numero}` }}
        </span>
    </div>
</template>
