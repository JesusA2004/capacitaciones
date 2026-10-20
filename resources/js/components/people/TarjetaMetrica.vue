<script setup lang="ts">
import type { Component } from 'vue';
import { computed } from 'vue';
import { TONO_BORDE, TONO_SUAVE, TONO_TEXTO } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';

/**
 * Conteo grande y claro (resúmenes de Contratos, Bajas, Evaluaciones…).
 * Con `clicable` se vuelve botón de filtro: `activa` lo marca seleccionado
 * (aria-pressed) y emite `click`.
 */
const props = withDefaults(
    defineProps<{
        etiqueta: string;
        valor: string | number;
        icono?: Component | null;
        tono?: Tono;
        detalle?: string | null;
        clicable?: boolean;
        activa?: boolean;
    }>(),
    {
        icono: null,
        tono: 'neutro',
        detalle: null,
        clicable: false,
        activa: false,
    },
);

defineEmits<{ click: [] }>();

const clases = computed(() =>
    cn(
        'group relative flex min-w-0 flex-col gap-2 rounded-2xl border bg-card p-3 text-left shadow-sm sm:p-4',
        props.clicable && 'tarjeta-interactiva cursor-pointer',
        props.activa
            ? cn(
                  'border-2 ring-2 ring-offset-0',
                  TONO_BORDE[props.tono],
                  'ring-oro/20',
              )
            : 'border-border/60',
    ),
);
</script>

<template>
    <component
        :is="clicable ? 'button' : 'div'"
        :type="clicable ? 'button' : undefined"
        :class="clases"
        :aria-pressed="clicable ? activa : undefined"
        @click="clicable && $emit('click')"
    >
        <div class="flex items-start justify-between gap-2">
            <span
                class="text-xs leading-tight font-medium text-muted-foreground sm:text-sm"
                >{{ etiqueta }}</span
            >
            <span
                v-if="icono"
                :class="
                    cn(
                        'flex size-8 shrink-0 items-center justify-center rounded-xl sm:size-9',
                        TONO_SUAVE[tono],
                    )
                "
                aria-hidden="true"
            >
                <component :is="icono" class="size-4 sm:size-5" />
            </span>
        </div>
        <span
            :class="
                cn(
                    'text-3xl leading-none font-bold tracking-tight tabular-nums sm:text-4xl',
                    valor === 0 || valor === '0'
                        ? 'text-muted-foreground/60'
                        : TONO_TEXTO[tono],
                )
            "
            >{{ valor }}</span
        >
        <span v-if="detalle" class="text-xs text-muted-foreground">{{
            detalle
        }}</span>
    </component>
</template>
