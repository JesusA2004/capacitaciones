<script setup lang="ts">
import { computed } from 'vue';
import { formatoMoneda } from '@/lib/seguimiento';

/**
 * Mini gráfica de barras (SVG, sin librería) con las semanas del mes: se
 * usa en cada renglón de las tablas. La semana elegida se resalta.
 */
const props = defineProps<{
    valores: number[];
    etiquetas?: string[];
    resaltada?: number | null;
}>();

const maximo = computed(() => Math.max(1, ...props.valores.map((v) => Math.abs(v))));
const ancho = computed(() => Math.max(1, props.valores.length) * 12);
const resumen = computed(() =>
    props.valores
        .map((v, i) => `${props.etiquetas?.[i] ?? `Semana ${i + 1}`}: ${formatoMoneda(v)}`)
        .join(' · '),
);
</script>

<template>
    <svg
        :viewBox="`0 0 ${ancho} 28`"
        :width="ancho"
        height="28"
        role="img"
        :aria-label="resumen"
        class="overflow-visible"
    >
        <title>{{ resumen }}</title>
        <rect
            v-for="(valor, i) in valores"
            :key="i"
            :x="i * 12 + 1"
            :y="28 - Math.max(2, (Math.abs(valor) / maximo) * 26)"
            width="9"
            :height="Math.max(2, (Math.abs(valor) / maximo) * 26)"
            rx="2"
            class="transition-all duration-500"
            :style="{
                fill: resaltada === null || resaltada === undefined || resaltada === i
                    ? 'var(--chart-1)'
                    : 'var(--pastel-borde)',
            }"
        />
    </svg>
</template>
