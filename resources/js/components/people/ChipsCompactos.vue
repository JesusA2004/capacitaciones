<script setup lang="ts">
import { computed, ref } from 'vue';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';

/**
 * Lista corta de chips (datos faltantes, documentos pendientes…) que
 * muestra los primeros `maximo` y un «+N» que despliega el resto, en vez
 * de un párrafo larguísimo.
 */
const props = withDefaults(
    defineProps<{
        elementos: string[];
        maximo?: number;
        tono?: Tono;
    }>(),
    { maximo: 3, tono: 'peligro' },
);

const expandido = ref(false);
const visibles = computed(() =>
    expandido.value ? props.elementos : props.elementos.slice(0, props.maximo),
);
const ocultos = computed(() => props.elementos.length - props.maximo);
</script>

<template>
    <ul class="flex flex-wrap items-center gap-1.5">
        <li
            v-for="e in visibles"
            :key="e"
            :class="
                cn(
                    'max-w-full truncate rounded-full px-2 py-0.5 text-xs',
                    TONO_SUAVE[tono],
                )
            "
            :title="e"
        >
            {{ e }}
        </li>
        <li v-if="ocultos > 0">
            <button
                type="button"
                class="rounded-full border border-border bg-card px-2 py-0.5 text-xs font-semibold text-foreground transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="expandido"
                @click="expandido = !expandido"
            >
                {{ expandido ? 'Ver menos' : `+${ocultos}` }}
            </button>
        </li>
    </ul>
</template>
