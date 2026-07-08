<script setup lang="ts">
import type { Component } from 'vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';

/**
 * Resumen de métricas arriba de un CRUD (total, activos, etc.), reutilizando
 * MetricCard del dashboard para que las pantallas administrativas hablen el
 * mismo lenguaje visual. Cada página decide qué cifras son honestas de
 * mostrar (normalmente vienen del backend, no se inventan a partir de la
 * página actual de resultados).
 */
defineProps<{
    estadisticas: {
        etiqueta: string;
        valor: string | number;
        subvalor?: string;
        icono?: Component;
        tono?: 'default' | 'success' | 'warning' | 'danger' | 'info';
    }[];
}>();
</script>

<template>
    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
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
