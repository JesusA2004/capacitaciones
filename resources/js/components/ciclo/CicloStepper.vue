<script setup lang="ts">
import {
    Check,
    ChevronLeft,
    ChevronRight,
    CircleDot,
    OctagonX,
} from '@lucide/vue';
import { nextTick, onMounted } from 'vue';
import { useDesplazamientoHorizontal } from '@/composables/useDesplazamientoHorizontal';
import type { PasoCiclo } from '@/types';

defineProps<{ pasos: PasoCiclo[] }>();

// En escritorio los pasos van en una sola fila que puede desbordar (13+
// pasos a 1024 px): flechas para recorrerla y el paso actual a la vista.
const { riel, alInicio, alFinal, mover, centrar } =
    useDesplazamientoHorizontal();

onMounted(async () => {
    await nextTick();
    centrar('[aria-current="step"]', false);
});
</script>

<template>
    <div class="relative">
        <button
            v-show="!(alInicio && alFinal)"
            type="button"
            class="absolute top-1/2 left-1 z-20 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-md transition hover:bg-crema focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-0 lg:flex"
            :disabled="alInicio"
            aria-label="Ver pasos anteriores"
            @click="mover(-1)"
        >
            <ChevronLeft class="size-5" aria-hidden="true" />
        </button>
        <button
            v-show="!(alInicio && alFinal)"
            type="button"
            class="absolute top-1/2 right-1 z-20 hidden size-10 -translate-y-1/2 items-center justify-center rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-md transition hover:bg-crema focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:pointer-events-none disabled:opacity-0 lg:flex"
            :disabled="alFinal"
            aria-label="Ver pasos siguientes"
            @click="mover(1)"
        >
            <ChevronRight class="size-5" aria-hidden="true" />
        </button>
        <ol
            ref="riel"
            class="grid w-full grid-cols-2 gap-x-3 gap-y-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 sm:grid-cols-3 lg:scroll-x-limpio lg:snap-x lg:snap-proximity lg:scroll-px-12 lg:auto-cols-[minmax(5.25rem,1fr)] lg:grid-flow-col lg:grid-cols-none lg:gap-x-0"
            aria-label="Avance del proceso"
        >
            <li
                v-for="(paso, indice) in pasos"
                :key="paso.clave"
                data-paso
                class="relative flex min-w-0 items-center gap-2 lg:snap-start lg:flex-col lg:items-center lg:gap-1.5 lg:text-center"
            >
                <span
                    class="relative z-10 flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold transition-colors duration-300"
                    :class="{
                        'border-[var(--mrl-petroleo)] bg-[var(--mrl-petroleo)] text-white':
                            paso.estado === 'completado',
                        'border-[var(--mrl-dorado)] bg-[var(--mrl-dorado)]/15 text-[var(--mrl-dorado-oscuro)] ring-4 ring-[var(--mrl-dorado)]/15':
                            paso.estado === 'actual',
                        'border-[var(--mrl-rojo)] bg-[var(--mrl-rojo)]/10 text-[var(--mrl-rojo)]':
                            paso.estado === 'detenido',
                        'border-[var(--mrl-borde)] text-[var(--mrl-texto-suave)]':
                            paso.estado === 'pendiente',
                    }"
                    :aria-current="
                        paso.estado === 'actual' ? 'step' : undefined
                    "
                >
                    <Check v-if="paso.estado === 'completado'" class="size-4" />
                    <CircleDot
                        v-else-if="paso.estado === 'actual'"
                        class="size-4"
                    />
                    <OctagonX
                        v-else-if="paso.estado === 'detenido'"
                        class="size-4"
                    />
                    <template v-else>{{ indice + 1 }}</template>
                </span>
                <span
                    class="min-w-0 text-xs leading-tight font-medium break-words hyphens-auto lg:px-0.5 lg:text-[11px] xl:text-xs 2xl:text-sm"
                    :class="
                        paso.estado === 'pendiente'
                            ? 'text-[var(--mrl-texto-suave)]'
                            : 'text-[var(--mrl-texto)]'
                    "
                    >{{ paso.etiqueta }}</span
                >
                <span
                    v-if="indice < pasos.length - 1"
                    class="absolute top-3.5 right-[calc(-50%+1.125rem)] left-[calc(50%+1.125rem)] hidden h-px lg:block"
                    aria-hidden="true"
                    :class="
                        paso.estado === 'completado'
                            ? 'bg-[var(--mrl-petroleo)]'
                            : 'bg-[var(--mrl-borde)]'
                    "
                />
            </li>
        </ol>
    </div>
</template>
