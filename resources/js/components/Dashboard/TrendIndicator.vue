<script setup lang="ts">
import { Minus, TrendingDown, TrendingUp } from '@lucide/vue';
import { computed } from 'vue';

/**
 * ▲/▼ + variación porcentual frente a un valor de referencia (mes/periodo
 * anterior). `positivoEsBueno` invierte los colores para métricas donde
 * subir es malo (p. ej. "capacitaciones vencidas").
 */
const props = withDefaults(
    defineProps<{
        valorActual: number;
        valorAnterior: number;
        positivoEsBueno?: boolean;
    }>(),
    {
        positivoEsBueno: true,
    },
);

const variacion = computed(() => {
    if (props.valorAnterior === 0) {
        return props.valorActual === 0 ? 0 : 100;
    }

    return Math.round(
        ((props.valorActual - props.valorAnterior) / props.valorAnterior) * 100,
    );
});

const tono = computed(() => {
    if (variacion.value === 0) {
        return 'neutro';
    }

    const esSubida = variacion.value > 0;
    const esBueno = props.positivoEsBueno ? esSubida : !esSubida;

    return esBueno ? 'bueno' : 'malo';
});
</script>

<template>
    <span
        class="inline-flex items-center gap-1 text-xs font-medium"
        :class="{
            'text-success': tono === 'bueno',
            'text-destructive': tono === 'malo',
            'text-muted-foreground': tono === 'neutro',
        }"
    >
        <TrendingUp v-if="variacion > 0" class="size-3.5" />
        <TrendingDown v-else-if="variacion < 0" class="size-3.5" />
        <Minus v-else class="size-3.5" />
        {{ variacion > 0 ? '+' : '' }}{{ variacion }}%
    </span>
</template>
