<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Component } from 'vue';
import MiniSparkline from '@/components/Dashboard/MiniSparkline.vue';
import TrendIndicator from '@/components/Dashboard/TrendIndicator.vue';

/**
 * La tarjeta de número grande de un dashboard: valor + etiqueta + icono,
 * con tendencia y/o sparkline opcionales. `href` la convierte en un enlace
 * completo (p. ej. "cursos en progreso" → Mi capacitación).
 */
type Tono = 'default' | 'success' | 'warning' | 'danger' | 'info';

withDefaults(
    defineProps<{
        titulo: string;
        valor: string | number;
        icono?: Component;
        tono?: Tono;
        href?: string;
        tendenciaActual?: number;
        tendenciaAnterior?: number;
        tendenciaPositivoEsBueno?: boolean;
        sparkline?: number[];
    }>(),
    {
        tono: 'default',
        tendenciaPositivoEsBueno: true,
    },
);

const TONOS: Record<Tono, string> = {
    default: 'bg-primary/10 text-primary',
    success: 'bg-success/10 text-success',
    warning: 'bg-warning/10 text-warning',
    danger: 'bg-destructive/10 text-destructive',
    info: 'bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]',
};
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href"
        class="group relative flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg"
    >
        <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
                <p class="truncate text-xs font-medium text-muted-foreground">
                    {{ titulo }}
                </p>
                <p class="mt-1 text-2xl font-semibold tabular-nums">
                    {{ valor }}
                </p>
            </div>
            <span
                v-if="icono"
                class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                :class="TONOS[tono]"
            >
                <component :is="icono" class="size-4.5" />
            </span>
        </div>

        <div
            v-if="tendenciaActual !== undefined || sparkline?.length"
            class="flex items-center justify-between gap-2"
        >
            <TrendIndicator
                v-if="
                    tendenciaActual !== undefined &&
                    tendenciaAnterior !== undefined
                "
                :valor-actual="tendenciaActual"
                :valor-anterior="tendenciaAnterior"
                :positivo-es-bueno="tendenciaPositivoEsBueno"
            />
            <span v-else />
            <MiniSparkline v-if="sparkline?.length" :valores="sparkline" />
        </div>
    </component>
</template>
