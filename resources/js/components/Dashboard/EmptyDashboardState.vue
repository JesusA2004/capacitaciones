<script setup lang="ts">
import { BarChart3 } from '@lucide/vue';
import type { Component } from 'vue';

/**
 * Igual que Common/EmptyState.vue pero pensado para vivir dentro de una
 * DashboardChartCard: icono más pequeño y menos relleno vertical, para no
 * romper el ritmo visual de una grilla de tarjetas.
 */
withDefaults(
    defineProps<{
        icono?: Component;
        titulo?: string;
        descripcion?: string;
    }>(),
    {
        icono: () => BarChart3,
        titulo: 'Sin datos todavía',
        descripcion: 'Esta gráfica se llenará conforme haya actividad.',
    },
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-1.5 py-10 text-center"
    >
        <component :is="icono" class="size-6 text-muted-foreground/60" />
        <p class="text-sm font-medium text-muted-foreground">{{ titulo }}</p>
        <p
            v-if="descripcion"
            class="max-w-[22rem] text-xs text-muted-foreground/80"
        >
            {{ descripcion }}
        </p>
    </div>
</template>
