<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import { columnasBalanceadas } from '@/lib/utils';

/**
 * Resumen de métricas arriba de un CRUD (total, activos, etc.), reutilizando
 * MetricCard del dashboard para que las pantallas administrativas hablen el
 * mismo lenguaje visual. Cada página decide qué cifras son honestas de
 * mostrar (normalmente vienen del backend, no se inventan a partir de la
 * página actual de resultados).
 *
 * Las columnas dependen de CUÁNTAS métricas hay (columnasBalanceadas):
 * 5 tarjetas nunca quedan como 4 + 1 huérfana.
 */
const props = defineProps<{
    estadisticas: {
        etiqueta: string;
        valor: string | number;
        subvalor?: string;
        icono?: Component;
        tono?: 'default' | 'success' | 'warning' | 'danger' | 'info';
    }[];
}>();

const clases = computed(() => columnasBalanceadas(props.estadisticas.length));
</script>

<template>
    <div data-tour="indicadores" class="grid gap-3" :class="clases">
        <MetricCard
            v-for="(item, indice) in estadisticas"
            :key="indice"
            :titulo="item.etiqueta"
            :valor="item.valor"
            :subvalor="item.subvalor"
            :icono="item.icono"
            :tono="item.tono"
        />
    </div>
</template>
