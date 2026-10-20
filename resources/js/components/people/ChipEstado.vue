<script setup lang="ts">
import type { Component } from 'vue';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';

/**
 * Estado con color + ícono (en expediente, por generar, faltan datos…).
 * El color nunca va solo: el ícono y el texto dicen lo mismo para quien no
 * distingue colores.
 */
withDefaults(
    defineProps<{
        etiqueta: string;
        tono?: Tono;
        icono?: Component | null;
        tamano?: 'sm' | 'md';
        titulo?: string | null;
    }>(),
    { tono: 'neutro', icono: null, tamano: 'sm', titulo: null },
);
</script>

<template>
    <span
        :class="
            cn(
                'inline-flex w-fit max-w-full shrink-0 items-center gap-1.5 rounded-2xl font-medium',
                tamano === 'md'
                    ? 'px-3 py-1 text-sm [&>svg]:size-4'
                    : 'px-2.5 py-0.5 text-xs [&>svg]:size-3.5',
                TONO_SUAVE[tono],
            )
        "
        :title="titulo ?? undefined"
    >
        <component
            :is="icono"
            v-if="icono"
            class="shrink-0"
            aria-hidden="true"
        />
        <span class="min-w-0 leading-snug text-pretty">{{ etiqueta }}</span>
    </span>
</template>
