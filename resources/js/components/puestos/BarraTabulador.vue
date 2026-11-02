<script setup lang="ts">
import { computed } from 'vue';
import { getInitials } from '@/composables/useInitials';
import { formatoMoneda } from '@/lib/utils';
import type { RangoTabulador } from '@/types';

/**
 * Rango del tabulador como una barra: zona verde de mínimo a máximo, marcas
 * de mínimo / medio / máximo y, si se pasan, un punto por persona en su
 * sueldo (las que caen fuera del rango quedan en la zona rosa).
 */
const props = withDefaults(
    defineProps<{
        rango: RangoTabulador;
        personas?: { id: number; nombre: string; sueldo: number | null }[];
        compacta?: boolean;
    }>(),
    { personas: () => [], compacta: false },
);

const sueldos = computed(() =>
    props.personas.map((p) => p.sueldo).filter((s): s is number => s !== null),
);

const escala = computed(() => {
    const desde = Math.min(props.rango.minimo * 0.85, ...sueldos.value);
    const hasta = Math.max(props.rango.maximo * 1.15, ...sueldos.value);

    return { desde, hasta: hasta === desde ? desde + 1 : hasta };
});

function posicion(valor: number): string {
    const { desde, hasta } = escala.value;

    return `${((valor - desde) / (hasta - desde)) * 100}%`;
}

const marcas = computed(() => [
    { etiqueta: 'Mínimo', valor: props.rango.minimo },
    { etiqueta: 'Medio', valor: props.rango.medio },
    { etiqueta: 'Máximo', valor: props.rango.maximo },
]);
</script>

<template>
    <div
        class="relative mx-4"
        :class="compacta ? 'mt-2 mb-9 h-2.5' : 'mt-9 mb-12 h-3.5'"
        role="img"
        :aria-label="`Rango de ${formatoMoneda(rango.minimo)} a ${formatoMoneda(rango.maximo)}, medio ${formatoMoneda(rango.medio)}`"
    >
        <div class="absolute inset-0 rounded-full bg-danger-soft" />
        <div
            class="absolute inset-y-0 rounded-full bg-success-soft ring-1 ring-success/25"
            :style="{
                left: posicion(rango.minimo),
                right: `calc(100% - ${posicion(rango.maximo)})`,
            }"
        />
        <div
            v-for="marca in marcas"
            :key="marca.etiqueta"
            class="absolute -top-1.5 h-[calc(100%+0.75rem)] border-l-2 border-dashed border-foreground/40"
            :style="{ left: posicion(marca.valor) }"
        >
            <span
                class="absolute top-full mt-1 -translate-x-1/2 text-center leading-tight whitespace-nowrap"
                :class="compacta ? 'text-[10px]' : 'text-[11px]'"
            >
                <span class="block font-semibold">{{ marca.etiqueta }}</span>
                <span class="text-muted-foreground tabular-nums">{{
                    formatoMoneda(marca.valor)
                }}</span>
            </span>
        </div>
        <template v-for="persona in personas" :key="persona.id">
            <span
                v-if="persona.sueldo !== null"
                class="punto absolute top-1/2 grid size-7 -translate-x-1/2 -translate-y-1/2 place-items-center rounded-full bg-primary text-[9px] font-bold text-primary-foreground shadow-sm ring-2 ring-card"
                :title="`${persona.nombre}: ${formatoMoneda(persona.sueldo)}`"
                :style="{ left: posicion(persona.sueldo) }"
                >{{ getInitials(persona.nombre) }}</span
            >
        </template>
    </div>
</template>

<style scoped>
.punto {
    transition: transform 160ms ease;
}

.punto:hover {
    z-index: 1;
    transform: translate(-50%, -50%) scale(1.2);
}
</style>
