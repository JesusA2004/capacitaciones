<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Tarjeta de KPI reutilizable para encabezados de tablero (Vacantes,
 * Dashboard RH, etc.) — un solo estilo de "métrica" en toda la app en vez
 * de que cada pantalla arme su propia tarjeta suelta.
 */
defineProps<{
    etiqueta: string;
    valor: string | number;
    icono?: Component;
    colorClase?: string;
}>();
</script>

<template>
    <div
        class="group flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:border-oro/40 hover:shadow-md"
    >
        <div class="flex items-center justify-between">
            <span class="text-xs font-medium text-muted-foreground">{{
                etiqueta
            }}</span>
            <span
                v-if="icono"
                class="flex size-7 items-center justify-center rounded-lg transition-transform duration-200 group-hover:scale-110 motion-reduce:group-hover:scale-100"
                :class="colorClase ?? 'bg-muted text-muted-foreground'"
            >
                <component :is="icono" class="size-4" />
            </span>
        </div>
        <span class="text-2xl font-bold tabular-nums">{{ valor }}</span>
    </div>
</template>
