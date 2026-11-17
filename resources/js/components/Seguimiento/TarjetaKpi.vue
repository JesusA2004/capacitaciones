<script setup lang="ts">
import type { Component } from 'vue';
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';

/**
 * Indicador de Seguimiento: etiqueta, número animado y una nota corta. Un
 * valor que Reportes no trae (null) se pinta «—», nunca 0.
 */
withDefaults(
    defineProps<{
        etiqueta: string;
        valor: number | null;
        icono: Component;
        prefijo?: string;
        sufijo?: string;
        decimales?: number;
        nota?: string;
        tono?: 'card' | 'salvia' | 'crema' | 'menta';
    }>(),
    {
        prefijo: '',
        sufijo: '',
        decimales: 0,
        nota: undefined,
        tono: 'card',
    },
);

const fondos: Record<string, string> = {
    card: 'bg-card',
    salvia: 'bg-salvia',
    crema: 'bg-crema',
    menta: 'bg-success-soft',
};
</script>

<template>
    <div
        class="flex flex-col gap-1.5 rounded-2xl border border-border p-4 transicion-ui hover:shadow-md"
        :class="fondos[tono]"
    >
        <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium text-muted-foreground">
                {{ etiqueta }}
            </p>
            <span
                class="flex size-8 items-center justify-center rounded-xl bg-card/70 text-bronce"
                aria-hidden="true"
            >
                <component :is="icono" class="size-4" />
            </span>
        </div>
        <p class="text-2xl font-semibold tracking-tight tabular-nums">
            <NumeroAnimado
                v-if="valor !== null"
                :valor="valor"
                :prefijo="prefijo"
                :sufijo="sufijo"
                :decimales="decimales"
            />
            <span v-else class="text-muted-foreground">—</span>
        </p>
        <p v-if="nota" class="text-[11px] text-muted-foreground">{{ nota }}</p>
    </div>
</template>
