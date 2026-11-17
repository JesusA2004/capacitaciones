<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';

/**
 * Tarjeta KPI de Recepción documental: ícono con tono pastel, número
 * animado, subtítulo y, opcionalmente, una barra de avance (cumplimiento).
 * Los tonos salen de los tokens de resources/css/app.css (nada de hex).
 */
type Tono = 'info' | 'success' | 'warning' | 'danger' | 'neutro';

const props = withDefaults(
    defineProps<{
        etiqueta: string;
        valor: number;
        icono: Component;
        tono?: Tono;
        sufijo?: string;
        decimales?: number;
        subtitulo?: string | null;
        /** 0–100: pinta una barra de avance bajo el número. */
        avance?: number | null;
    }>(),
    { tono: 'neutro', sufijo: '', decimales: 0, subtitulo: null, avance: null },
);

const TONOS: Record<Tono, { icono: string; barra: string }> = {
    info: { icono: 'bg-info-soft text-info', barra: 'bg-info' },
    success: { icono: 'bg-success-soft text-success', barra: 'bg-success' },
    warning: { icono: 'bg-warning-soft text-warning', barra: 'bg-warning' },
    danger: {
        icono: 'bg-destructive/10 text-destructive',
        barra: 'bg-destructive',
    },
    neutro: { icono: 'bg-crema text-bronce', barra: 'bg-primary' },
};

const tono = computed(() => TONOS[props.tono]);
const ancho = computed(() =>
    props.avance === null
        ? '0%'
        : `${Math.max(0, Math.min(100, props.avance))}%`,
);
</script>

<template>
    <div
        class="group flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transicion-ui hover:-translate-y-0.5 hover:border-oro/40 hover:shadow-md motion-reduce:hover:translate-y-0"
    >
        <div class="flex items-start justify-between gap-3">
            <span class="text-xs font-medium text-muted-foreground">{{
                etiqueta
            }}</span>
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-xl transition-transform duration-200 group-hover:scale-110 motion-reduce:group-hover:scale-100"
                :class="tono.icono"
                aria-hidden="true"
            >
                <component :is="icono" class="size-4.5" />
            </span>
        </div>
        <span class="text-3xl leading-none font-bold tabular-nums">
            <NumeroAnimado
                :valor="valor"
                :sufijo="sufijo"
                :decimales="decimales"
            />
        </span>
        <div
            v-if="avance !== null"
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            :aria-valuenow="avance"
            aria-valuemin="0"
            aria-valuemax="100"
            :aria-label="etiqueta"
        >
            <div
                class="h-full rounded-full transition-[width] duration-700 ease-out motion-reduce:transition-none"
                :class="tono.barra"
                :style="{ width: ancho }"
            />
        </div>
        <span v-if="subtitulo" class="text-xs text-muted-foreground">{{
            subtitulo
        }}</span>
    </div>
</template>
