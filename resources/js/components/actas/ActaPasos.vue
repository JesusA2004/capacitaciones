<script setup lang="ts">
import { Check } from '@lucide/vue';
import { PASOS_ACTA } from '@/lib/actas';

/** Indicador de pasos de la captura del acta (clic para ir a un paso). */
const props = defineProps<{
    actual: number;
    /** Pasos que se muestran (el diálogo de alta solo usa 1 y 2). */
    pasos?: number[];
    deshabilitado?: boolean;
}>();

const emit = defineEmits<{ ir: [paso: number] }>();

const visibles = PASOS_ACTA.filter((p) => !props.pasos || props.pasos.includes(p.numero));
</script>

<template>
    <ol class="scroll-x-limpio flex gap-1.5 overflow-x-auto pb-1" aria-label="Pasos del acta">
        <li v-for="(p, i) in visibles" :key="p.numero" class="flex min-w-0 flex-1 items-center gap-1.5">
            <button
                type="button"
                class="group flex min-w-[8.5rem] flex-1 items-center gap-2 rounded-xl border px-2.5 py-2 text-left transition-all duration-150 hover:-translate-y-0.5 hover:shadow-sm disabled:pointer-events-none disabled:opacity-60 motion-reduce:hover:translate-y-0"
                :class="
                    p.numero === actual
                        ? 'border-[var(--mrl-petroleo)] bg-salvia'
                        : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] hover:border-oro/60'
                "
                :aria-current="p.numero === actual ? 'step' : undefined"
                :disabled="deshabilitado"
                @click="emit('ir', p.numero)"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors"
                    :class="
                        p.numero === actual
                            ? 'bg-[var(--mrl-petroleo)] text-white'
                            : p.numero < actual
                              ? 'bg-success-soft text-[var(--mrl-petroleo)]'
                              : 'bg-crema text-muted-foreground group-hover:bg-oro/30'
                    "
                >
                    <Check v-if="p.numero < actual" class="size-3.5" />
                    <template v-else>{{ i + 1 }}</template>
                </span>
                <span class="min-w-0">
                    <span class="block truncate text-xs font-medium">{{ p.titulo }}</span>
                    <span class="block truncate text-[11px] text-muted-foreground">{{ p.ayuda }}</span>
                </span>
            </button>
        </li>
    </ol>
</template>
