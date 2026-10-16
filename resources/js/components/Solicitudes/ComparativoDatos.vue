<script setup lang="ts">
import { ArrowRight } from '@lucide/vue';
import type { DatoPropuesto } from '@/types';

/**
 * Actualización de datos: «Completar dato faltante» (el expediente estaba
 * vacío) o «Cambio de información» (valor actual → propuesto). RH lo revisa
 * y solo al autorizar pasa al expediente.
 */
defineProps<{
    datos: DatoPropuesto[];
    aplicada: boolean;
}>();
</script>

<template>
    <div class="overflow-hidden rounded-2xl border border-border/60 bg-card">
        <div class="border-b border-border/60 px-5 py-3">
            <p class="font-semibold">
                Datos que se actualizarán en el expediente
            </p>
            <p class="text-xs text-muted-foreground">
                {{
                    aplicada
                        ? 'Ya se aplicaron al autorizar.'
                        : 'Se aplican solo cuando Recursos Humanos autoriza la solicitud.'
                }}
            </p>
        </div>
        <ul class="divide-y divide-border/40">
            <li
                v-for="dato in datos"
                :key="dato.campo"
                class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 text-sm transition-colors hover:bg-crema/40"
            >
                <span class="min-w-40 font-medium">{{ dato.etiqueta }}</span>
                <span
                    class="rounded-full px-2 py-0.5 text-xs font-medium"
                    :class="
                        dato.clase === 'completar'
                            ? 'bg-info-soft text-[var(--mrl-petroleo)]'
                            : 'bg-warning-soft text-[var(--mrl-texto)]'
                    "
                >
                    {{
                        dato.clase === 'completar'
                            ? 'Completar dato faltante'
                            : 'Cambio de información'
                    }}
                </span>
                <span class="flex flex-1 flex-wrap items-center gap-2">
                    <template v-if="dato.clase === 'cambio'">
                        <span
                            class="text-muted-foreground line-through decoration-muted-foreground/50"
                            >{{ dato.actual ?? 'Sin capturar' }}</span
                        >
                        <ArrowRight class="size-3.5 text-muted-foreground" />
                    </template>
                    <span class="font-semibold text-primary">{{
                        dato.propuesto
                    }}</span>
                </span>
            </li>
        </ul>
    </div>
</template>
