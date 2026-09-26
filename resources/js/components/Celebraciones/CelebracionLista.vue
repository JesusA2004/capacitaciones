<script setup lang="ts">
import { computed, ref } from 'vue';
import CelebracionPersonaFila from '@/components/Celebraciones/CelebracionPersonaFila.vue';
import { Button } from '@/components/ui/button';
import type { EventoCelebracion } from '@/types';

/**
 * Lista compacta de personas (Próximos / listado del periodo) para ambos
 * tipos de celebración. Un solo contenedor con filas separadas por un
 * borde sutil — nunca 15 cajas dentro de otra caja.
 *
 * Las columnas dependen del ancho REAL del contenedor (container query):
 * en la columna lateral junto al calendario es una sola; a todo lo ancho
 * pasa a 2–3 columnas para no dejar filas larguísimas.
 * Con muchas personas muestra las primeras `limite` y un "Ver todas"
 * (sin scroll anidado).
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        eventos: EventoCelebracion[];
        hoy: string;
        vacio: string;
        limite?: number;
    }>(),
    { limite: 8 },
);

const expandida = ref(false);
const visibles = computed(() => (expandida.value ? props.eventos : props.eventos.slice(0, props.limite)));
</script>

<template>
    <section class="@container flex min-w-0 flex-col rounded-xl border bg-card" :aria-label="titulo">
        <header class="flex flex-wrap items-center justify-between gap-2 border-b px-4 py-2.5">
            <h2 class="text-sm font-semibold">
                {{ titulo }}
                <span class="ml-1 font-normal text-muted-foreground tabular-nums">{{ eventos.length }}</span>
            </h2>
            <slot name="acciones" />
        </header>

        <p v-if="eventos.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">{{ vacio }}</p>

        <ul v-else class="grid grid-cols-1 gap-x-6 px-4 @2xl:grid-cols-2 @5xl:grid-cols-3">
            <li v-for="evento in visibles" :key="`${evento.colaborador_id}-${evento.fecha}`" class="min-w-0 border-b border-border/60 last:border-b-0 @2xl:[&:nth-last-child(-n+2)]:border-b-0 @5xl:[&:nth-last-child(-n+3)]:border-b-0">
                <CelebracionPersonaFila :evento="evento" :hoy="hoy" />
            </li>
        </ul>

        <div v-if="eventos.length > limite" class="border-t px-4 py-2">
            <Button variant="ghost" size="sm" class="w-full" @click="expandida = !expandida">
                {{ expandida ? 'Ver menos' : `Ver todas (${eventos.length})` }}
            </Button>
        </div>
    </section>
</template>
