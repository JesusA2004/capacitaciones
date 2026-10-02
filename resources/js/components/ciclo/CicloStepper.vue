<script setup lang="ts">
import { Check, CircleDot, OctagonX } from '@lucide/vue';
import type { PasoCiclo } from '@/types';

defineProps<{ pasos: PasoCiclo[] }>();
</script>

<template>
    <ol
        class="flex w-full flex-wrap items-center gap-y-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
        aria-label="Avance del proceso"
    >
        <li
            v-for="(paso, indice) in pasos"
            :key="paso.clave"
            class="flex min-w-0 flex-1 items-center gap-2"
        >
            <span
                class="flex size-7 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
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
                class="truncate text-xs font-medium sm:text-sm"
                :class="
                    paso.estado === 'pendiente'
                        ? 'text-[var(--mrl-texto-suave)]'
                        : 'text-[var(--mrl-texto)]'
                "
                >{{ paso.etiqueta }}</span
            >
            <span
                v-if="indice < pasos.length - 1"
                class="mx-1 hidden h-px flex-1 sm:block"
                :class="
                    paso.estado === 'completado'
                        ? 'bg-[var(--mrl-petroleo)]'
                        : 'bg-[var(--mrl-borde)]'
                "
            />
        </li>
    </ol>
</template>
