<script setup lang="ts">
import { ShieldQuestion } from '@lucide/vue';
import {
    CLASE_POR_CLASIFICAR,
    claseChipRegion,
} from '@/lib/organigramaEditable';
import type { RegionOrganigrama } from '@/types';

/**
 * Botones Q1 · Q3 · Por clasificar (un clic = clasificado). `sugeridaId`:
 * la región de su sucursal en la matriz comercial, marcada como sugerencia
 * (nunca se aplica sola).
 */
defineProps<{
    regiones: RegionOrganigrama[];
    actualId: number | null;
    sugeridaId?: number | null;
    deshabilitado?: boolean;
    grande?: boolean;
}>();

const emit = defineEmits<{ elegir: [regionId: number | null] }>();
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-1.5"
        role="radiogroup"
        aria-label="Lado del organigrama (Q1 / Q3)"
    >
        <button
            v-for="r in regiones"
            :key="r.id"
            type="button"
            role="radio"
            :aria-checked="actualId === r.id"
            :disabled="deshabilitado"
            class="relative rounded-full border font-bold tracking-wide transition-all disabled:opacity-50"
            :class="[
                grande ? 'px-5 py-2 text-sm' : 'px-3 py-1 text-xs',
                actualId === r.id
                    ? [claseChipRegion(r.codigo), 'shadow-sm']
                    : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
            ]"
            :title="
                sugeridaId === r.id
                    ? `Sugerido por la matriz comercial (${r.nombre})`
                    : r.nombre
            "
            @click="emit('elegir', r.id)"
        >
            {{ r.codigo }}
            <span
                v-if="sugeridaId === r.id && actualId !== r.id && grande"
                class="absolute -top-2.5 left-1/2 -translate-x-1/2 rounded-full bg-oro px-1.5 text-[9px] leading-4 font-semibold text-primary-foreground"
                >matriz</span
            >
            <span
                v-else-if="sugeridaId === r.id && actualId !== r.id"
                class="absolute -top-0.5 -right-0.5 size-2.5 rounded-full border-2 border-card bg-oro"
                aria-hidden="true"
            />
        </button>
        <button
            type="button"
            role="radio"
            :aria-checked="actualId === null"
            :disabled="deshabilitado"
            class="inline-flex items-center gap-1 rounded-full border transition-all disabled:opacity-50"
            :class="[
                grande ? 'px-4 py-2 text-sm' : 'px-2.5 py-1 text-xs',
                actualId === null
                    ? CLASE_POR_CLASIFICAR
                    : 'border-dashed border-border bg-card text-muted-foreground hover:text-foreground',
            ]"
            @click="emit('elegir', null)"
        >
            <ShieldQuestion class="size-3.5" />
            Por clasificar
        </button>
    </div>
</template>
