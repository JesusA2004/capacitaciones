<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Variante compacta de KpiCard para métricas secundarias que se muestran
 * varias a la vez en una fila (p. ej. "colaboradores activos", "calificación
 * promedio"): icono + etiqueta + valor + subvalor opcional, en una sola
 * línea en vez de la tarjeta grande.
 */
withDefaults(
    defineProps<{
        titulo: string;
        valor: string | number;
        subvalor?: string;
        icono?: Component;
        tono?: 'default' | 'success' | 'warning' | 'danger' | 'info';
    }>(),
    {
        tono: 'default',
    },
);

const TONOS: Record<string, string> = {
    default: 'bg-primary/10 text-primary',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300',
    info: 'bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]',
};
</script>

<template>
    <div
        class="flex items-center gap-3.5 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
    >
        <span
            v-if="icono"
            class="flex size-11 shrink-0 items-center justify-center rounded-xl"
            :class="TONOS[tono]"
        >
            <component :is="icono" class="size-5" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="line-clamp-2 text-sm leading-tight text-muted-foreground">{{ titulo }}</p>
            <p class="flex items-baseline gap-1.5">
                <span class="text-2xl font-bold tracking-tight tabular-nums">{{
                    valor
                }}</span>
                <span v-if="subvalor" class="text-xs text-muted-foreground">{{
                    subvalor
                }}</span>
            </p>
        </div>
    </div>
</template>
