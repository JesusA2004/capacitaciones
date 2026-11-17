<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';

/**
 * Calificación 0-100 en un anillo que se llena al aparecer, con la marca de
 * la mínima aprobatoria. Verde suave si aprueba, ámbar si no; «—» sin dato.
 */
const props = withDefaults(
    defineProps<{
        calificacion: number | null;
        minima?: number | null;
        tamano?: number;
    }>(),
    { minima: null, tamano: 120 },
);

const radio = 52;
const circunferencia = 2 * Math.PI * radio;
const montado = ref(false);

onMounted(() => requestAnimationFrame(() => (montado.value = true)));

const avance = computed(() =>
    props.calificacion === null || !montado.value
        ? 0
        : Math.max(0, Math.min(100, props.calificacion)),
);
const aprueba = computed(
    () =>
        props.calificacion !== null &&
        (props.minima === null || props.calificacion >= props.minima),
);
const marca = computed(() => {
    if (props.minima === null) {
        return null;
    }

    const angulo = (props.minima / 100) * 2 * Math.PI - Math.PI / 2;

    return {
        x: 60 + Math.cos(angulo) * radio,
        y: 60 + Math.sin(angulo) * radio,
    };
});
</script>

<template>
    <div
        class="relative inline-flex items-center justify-center"
        :style="{ width: `${tamano}px`, height: `${tamano}px` }"
        role="img"
        :aria-label="
            calificacion === null
                ? 'Sin calificación'
                : `Calificación ${calificacion} de 100`
        "
    >
        <svg viewBox="0 0 120 120" class="size-full -rotate-0">
            <circle
                cx="60"
                cy="60"
                :r="radio"
                fill="none"
                stroke="var(--muted)"
                stroke-width="10"
            />
            <circle
                cx="60"
                cy="60"
                :r="radio"
                fill="none"
                :stroke="aprueba ? 'var(--pastel-esmeralda)' : 'var(--pastel-oro)'"
                stroke-width="10"
                stroke-linecap="round"
                :stroke-dasharray="circunferencia"
                :stroke-dashoffset="circunferencia * (1 - avance / 100)"
                transform="rotate(-90 60 60)"
                class="transition-[stroke-dashoffset] duration-1000 ease-out motion-reduce:transition-none"
            />
            <circle
                v-if="marca"
                :cx="marca.x"
                :cy="marca.y"
                r="4"
                fill="var(--pastel-petroleo)"
                stroke="var(--card)"
                stroke-width="2"
            />
        </svg>
        <span class="absolute inset-0 flex flex-col items-center justify-center">
            <span
                class="font-display text-2xl font-semibold tracking-tight tabular-nums"
            >
                <NumeroAnimado
                    v-if="calificacion !== null"
                    :valor="calificacion"
                    :decimales="calificacion % 1 === 0 ? 0 : 1"
                    :duracion="900"
                />
                <template v-else>—</template>
            </span>
            <span
                v-if="minima !== null"
                class="text-[10px] text-muted-foreground"
                >mín. {{ minima }}</span
            >
        </span>
    </div>
</template>
