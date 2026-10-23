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
    danger: 'bg-warning-soft text-warning',
    info: 'bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]',
};
</script>

<template>
    <div
        class="flex items-center gap-2.5 rounded-2xl border border-border/60 bg-card p-3 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md sm:gap-3.5 sm:p-4"
    >
        <span
            v-if="icono"
            class="flex size-9 shrink-0 items-center justify-center rounded-xl sm:size-11"
            :class="TONOS[tono]"
        >
            <component :is="icono" class="size-4 sm:size-5" />
        </span>
        <div class="min-w-0 flex-1">
            <p
                class="line-clamp-3 text-[13px] leading-tight text-pretty break-words text-muted-foreground sm:line-clamp-2 sm:text-sm"
            >
                {{ titulo }}
            </p>
            <p class="flex flex-wrap items-baseline gap-x-1.5">
                <span
                    class="text-xl font-bold tracking-tight tabular-nums sm:text-2xl"
                    >{{ valor }}</span
                >
                <span v-if="subvalor" class="text-xs text-muted-foreground">{{
                    subvalor
                }}</span>
            </p>
        </div>
    </div>
</template>
