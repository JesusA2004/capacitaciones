<script setup lang="ts">
import { ArrowDownRight, ArrowUpRight, Info, Minus } from '@lucide/vue';
import type { Component } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { TONO_SUAVE, TONO_TEXTO } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';

/**
 * Indicador del resumen de Rotación: valor grande, contexto corto, fórmula
 * en un tooltip y comparación contra el periodo anterior. Si es clicable,
 * toda la tarjeta abre el histórico ya filtrado (botón estirado); las
 * acciones secundarias van en el slot `accion`.
 */
withDefaults(
    defineProps<{
        etiqueta: string;
        valor: string;
        detalle?: string | null;
        nota?: string | null;
        definicion?: string | null;
        icono?: Component | null;
        tono?: Tono;
        clicable?: boolean;
        textoAccion?: string;
        /** Diferencia contra el periodo anterior (subir = más bajas). */
        delta?: number | null;
        textoDelta?: string | null;
        indice?: number;
        atenuado?: boolean;
    }>(),
    {
        detalle: null,
        nota: null,
        definicion: null,
        icono: null,
        tono: 'neutro',
        clicable: false,
        textoAccion: 'Ver bajas',
        delta: null,
        textoDelta: null,
        indice: 0,
        atenuado: false,
    },
);

defineEmits<{ click: [] }>();
</script>

<template>
    <article
        :class="
            cn(
                'relative flex min-w-0 aparece-escalonado flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm',
                clicable && 'tarjeta-interactiva',
            )
        "
        :style="{ '--i': indice }"
    >
        <div class="flex items-start justify-between gap-2">
            <h3
                class="text-xs leading-tight font-medium text-muted-foreground sm:text-sm"
            >
                {{ etiqueta }}
            </h3>
            <div class="relative z-10 flex shrink-0 items-center gap-1">
                <Tooltip v-if="definicion">
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="grid size-6 place-items-center rounded-full text-muted-foreground transition-colors hover:bg-crema/60 hover:text-foreground"
                            :aria-label="`Cómo se calcula: ${definicion}`"
                        >
                            <Info class="size-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-64 text-left">{{
                        definicion
                    }}</TooltipContent>
                </Tooltip>
                <span
                    v-if="icono"
                    :class="
                        cn(
                            'grid size-8 place-items-center rounded-xl',
                            TONO_SUAVE[tono],
                        )
                    "
                    aria-hidden="true"
                >
                    <component :is="icono" class="size-4" />
                </span>
            </div>
        </div>

        <p
            :class="
                cn(
                    'text-3xl leading-none font-bold tracking-tight tabular-nums',
                    atenuado ? 'text-muted-foreground/70' : TONO_TEXTO[tono],
                )
            "
        >
            {{ valor }}
        </p>

        <p v-if="detalle" class="text-xs text-muted-foreground">
            {{ detalle }}
        </p>

        <p
            v-if="textoDelta"
            class="inline-flex items-center gap-1 text-xs"
            :class="
                delta === null || delta === 0
                    ? 'text-muted-foreground'
                    : delta > 0
                      ? 'text-semaforo-sube'
                      : 'text-semaforo-baja'
            "
        >
            <ArrowUpRight
                v-if="delta !== null && delta > 0"
                class="size-3.5"
                aria-hidden="true"
            />
            <ArrowDownRight
                v-else-if="delta !== null && delta < 0"
                class="size-3.5"
                aria-hidden="true"
            />
            <Minus v-else class="size-3.5" aria-hidden="true" />
            {{ textoDelta }}
        </p>

        <p v-if="nota" class="text-[11px] text-muted-foreground">
            {{ nota }}
        </p>

        <div v-if="$slots.accion" class="relative z-10 mt-auto pt-1">
            <slot name="accion" />
        </div>

        <button
            v-if="clicable"
            type="button"
            class="absolute inset-0 rounded-2xl focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-oro"
            :aria-label="`${etiqueta}: ${valor}. ${textoAccion}`"
            @click="$emit('click')"
        />
    </article>
</template>
