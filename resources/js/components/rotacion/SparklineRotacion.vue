<script setup lang="ts">
import { computed } from 'vue';

/** Mini serie mensual de bajas (solo forma; el número está en la fila). */
const props = withDefaults(
    defineProps<{ serie: number[]; ancho?: number; alto?: number }>(),
    { ancho: 88, alto: 26 },
);

const puntos = computed(() => {
    const n = props.serie.length;
    const max = Math.max(1, ...props.serie);

    if (n === 0) {
        return [];
    }

    return props.serie.map((v, i) => ({
        x: n === 1 ? props.ancho / 2 : (i / (n - 1)) * (props.ancho - 4) + 2,
        y: props.alto - 3 - (v / max) * (props.alto - 6),
    }));
});

const linea = computed(() =>
    puntos.value.map((p) => `${p.x},${p.y}`).join(' '),
);
const area = computed(() =>
    puntos.value.length
        ? `${puntos.value[0].x},${props.alto} ${linea.value} ${puntos.value.at(-1)?.x},${props.alto}`
        : '',
);
const ultimo = computed(() => puntos.value.at(-1) ?? null);
</script>

<template>
    <svg
        :width="ancho"
        :height="alto"
        :viewBox="`0 0 ${ancho} ${alto}`"
        class="shrink-0 overflow-visible"
        aria-hidden="true"
    >
        <polygon v-if="area" :points="area" class="fill-chart-1/10" />
        <polyline
            :points="linea"
            fill="none"
            class="stroke-chart-1"
            stroke-width="1.5"
            stroke-linejoin="round"
            stroke-linecap="round"
        />
        <circle
            v-if="ultimo"
            :cx="ultimo.x"
            :cy="ultimo.y"
            r="2.5"
            class="fill-chart-1"
        />
    </svg>
</template>
