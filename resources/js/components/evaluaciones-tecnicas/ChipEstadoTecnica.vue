<script setup lang="ts">
import { computed } from 'vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { ESTADO_TECNICA } from '@/lib/evaluacionesTecnicas';
import type { EstadoHitoTecnico } from '@/types';

/** Chip del estado de una evaluación técnica (tono + ícono + texto). */
const props = withDefaults(
    defineProps<{ estado: EstadoHitoTecnico; tamano?: 'sm' | 'md' }>(),
    { tamano: 'sm' },
);

const config = computed(() => ESTADO_TECNICA[props.estado]);
</script>

<template>
    <ChipEstado
        :etiqueta="config.etiqueta"
        :tono="config.tono"
        :icono="config.icono"
        :tamano="tamano"
    />
</template>
