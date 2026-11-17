<script setup lang="ts">
import { computed } from 'vue';

/**
 * Anillo de progreso (0–100) en SVG. El color sale de `currentColor`, así
 * que se pinta con una clase de texto de la paleta (text-esmeralda…).
 */
const props = withDefaults(
    defineProps<{
        valor: number;
        tamano?: number;
        grosor?: number;
    }>(),
    { tamano: 56, grosor: 6 },
);

const radio = computed(() => (props.tamano - props.grosor) / 2);
const circunferencia = computed(() => 2 * Math.PI * radio.value);
const porcentaje = computed(() => Math.max(0, Math.min(100, props.valor)));
const desfase = computed(
    () => circunferencia.value * (1 - porcentaje.value / 100),
);
</script>

<template>
    <div
        class="relative inline-flex shrink-0 items-center justify-center"
        :style="{ width: `${tamano}px`, height: `${tamano}px` }"
        role="progressbar"
        :aria-valuenow="porcentaje"
        aria-valuemin="0"
        aria-valuemax="100"
    >
        <svg :width="tamano" :height="tamano" class="-rotate-90">
            <circle
                :cx="tamano / 2"
                :cy="tamano / 2"
                :r="radio"
                fill="none"
                stroke="currentColor"
                :stroke-width="grosor"
                class="opacity-15"
            />
            <circle
                :cx="tamano / 2"
                :cy="tamano / 2"
                :r="radio"
                fill="none"
                stroke="currentColor"
                :stroke-width="grosor"
                stroke-linecap="round"
                :stroke-dasharray="circunferencia"
                :stroke-dashoffset="desfase"
                class="transition-[stroke-dashoffset] duration-700 ease-out motion-reduce:transition-none"
            />
        </svg>
        <span
            class="absolute text-xs font-semibold text-foreground tabular-nums"
        >
            <slot>{{ Math.round(porcentaje) }}%</slot>
        </span>
    </div>
</template>
