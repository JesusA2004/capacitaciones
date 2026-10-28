<script setup lang="ts">
import { Check, CircleDot, OctagonX } from '@lucide/vue';
import type { PasoCiclo } from '@/types';

defineProps<{ pasos: PasoCiclo[] }>();
</script>

<template>
    <ol
        class="grid w-full grid-cols-2 gap-x-3 gap-y-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 sm:grid-cols-3 lg:scroll-x-limpio lg:auto-cols-[minmax(5.25rem,1fr)] lg:grid-flow-col lg:grid-cols-none lg:gap-x-0"
        aria-label="Avance del proceso"
    >
        <li
            v-for="(paso, indice) in pasos"
            :key="paso.clave"
            class="relative flex min-w-0 items-center gap-2 lg:flex-col lg:items-center lg:gap-1.5 lg:text-center"
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
                :aria-current="paso.estado === 'actual' ? 'step' : undefined"
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
                class="min-w-0 text-xs leading-tight font-medium break-words lg:px-0.5 lg:text-[11px] xl:text-xs 2xl:text-sm"
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
</template>
