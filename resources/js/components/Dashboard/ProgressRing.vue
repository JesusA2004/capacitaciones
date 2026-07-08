<script setup lang="ts">
import { computed } from 'vue';

/**
 * Anillo de progreso en SVG puro (sin librería externa): usado para
 * "avance general de capacitación" y otros porcentajes destacados donde una
 * barra lineal se siente pobre. El color cambia según el propio porcentaje
 * (rojo/ámbar/verde) salvo que se fuerce uno explícito con `color`.
 */
const props = withDefaults(
    defineProps<{
        porcentaje: number;
        tamano?: number;
        grosor?: number;
        color?: string;
        etiqueta?: string;
    }>(),
    {
        tamano: 96,
        grosor: 10,
    },
);

const radio = computed(() => (props.tamano - props.grosor) / 2);
const circunferencia = computed(() => 2 * Math.PI * radio.value);
const porcentajeAcotado = computed(() =>
    Math.min(100, Math.max(0, props.porcentaje)),
);
const offset = computed(
    () => circunferencia.value * (1 - porcentajeAcotado.value / 100),
);

const colorAnillo = computed(() => {
    if (props.color) {
        return props.color;
    }

    if (porcentajeAcotado.value >= 75) {
        return 'var(--success)';
    }

    if (porcentajeAcotado.value >= 40) {
        return 'var(--warning)';
    }

    return 'var(--destructive)';
});
</script>

<template>
    <div
        class="relative inline-flex items-center justify-center"
        :style="{ width: `${tamano}px`, height: `${tamano}px` }"
    >
        <svg :width="tamano" :height="tamano" class="-rotate-90">
            <circle
                :cx="tamano / 2"
                :cy="tamano / 2"
                :r="radio"
                :stroke-width="grosor"
                fill="none"
                class="text-muted/40"
                stroke="currentColor"
            />
            <circle
                :cx="tamano / 2"
                :cy="tamano / 2"
                :r="radio"
                :stroke-width="grosor"
                fill="none"
                :stroke="colorAnillo"
                stroke-linecap="round"
                :stroke-dasharray="circunferencia"
                :stroke-dashoffset="offset"
                class="transition-[stroke-dashoffset] duration-700 ease-out"
            />
        </svg>
        <div class="absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-lg font-semibold tabular-nums"
                >{{ Math.round(porcentajeAcotado) }}%</span
            >
            <span
                v-if="etiqueta"
                class="text-[0.65rem] text-muted-foreground"
                >{{ etiqueta }}</span
            >
        </div>
    </div>
</template>
