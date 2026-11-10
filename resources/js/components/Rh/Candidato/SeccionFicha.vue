<script setup lang="ts">
import type { Component } from 'vue';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';

/**
 * Bloque de la ficha del candidato: encabezado con ícono en círculo pastel,
 * título y una línea que explica qué hay dentro. Entra escalonado según
 * `indice` (la regla global de reduced-motion la apaga) y se eleva un poco
 * al pasar el mouse para que se sienta vivo, sin mover el layout.
 */
withDefaults(
    defineProps<{
        titulo: string;
        icono: Component;
        descripcion?: string | null;
        tono?: Tono;
        indice?: number;
        /** Número que acompaña al título (archivos, evaluaciones…). */
        contador?: number | null;
        etiquetaAria?: string | null;
    }>(),
    {
        descripcion: null,
        tono: 'primario',
        indice: 0,
        contador: null,
        etiquetaAria: null,
    },
);
</script>

<template>
    <section
        class="flex min-w-0 aparece-escalonado flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm transicion-ui hover:shadow-md sm:p-5"
        :style="{ '--i': indice }"
        :aria-label="etiquetaAria ?? titulo"
    >
        <header class="flex flex-wrap items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                :class="TONO_SUAVE[tono]"
                aria-hidden="true"
            >
                <component :is="icono" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2
                    class="flex flex-wrap items-center gap-2 text-base leading-tight font-semibold"
                >
                    {{ titulo }}
                    <span
                        v-if="contador !== null"
                        class="rounded-full bg-crema px-2 py-0.5 text-xs font-semibold text-bronce tabular-nums"
                        >{{ contador }}</span
                    >
                </h2>
                <p
                    v-if="descripcion"
                    class="mt-0.5 text-xs leading-snug break-words text-muted-foreground"
                >
                    {{ descripcion }}
                </p>
            </div>
            <div
                v-if="$slots.acciones"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="acciones" />
            </div>
        </header>
        <slot />
    </section>
</template>
