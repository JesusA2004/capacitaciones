<script setup lang="ts">
import { computed } from 'vue';

/**
 * Línea de tendencia diminuta e inline (sin ejes, sin tooltip) para meter
 * dentro de un KpiCard/MetricCard. Se implementa en SVG puro en vez de
 * Unovis: para un trazo de un solo color sin interacción, montar un
 * contenedor XY completo por tarjeta es más costo que beneficio.
 */
const props = withDefaults(
    defineProps<{
        valores: number[];
        ancho?: number;
        alto?: number;
        color?: string;
    }>(),
    {
        ancho: 72,
        alto: 24,
        color: 'var(--brand-secondary)',
    },
);

const puntos = computed(() => {
    if (props.valores.length < 2) {
        return '';
    }

    const minimo = Math.min(...props.valores);
    const maximo = Math.max(...props.valores);
    const rango = maximo - minimo || 1;
    const paso = props.ancho / (props.valores.length - 1);

    return props.valores
        .map((valor, i) => {
            const x = i * paso;
            const y = props.alto - ((valor - minimo) / rango) * props.alto;

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg
        :width="ancho"
        :height="alto"
        :viewBox="`0 0 ${ancho} ${alto}`"
        class="overflow-visible"
    >
        <polyline
            v-if="puntos"
            :points="puntos"
            fill="none"
            :stroke="color"
            stroke-width="1.75"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
