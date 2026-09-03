<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { ref } from 'vue';
import OrganigramaTarjeta from '@/components/Administracion/OrganigramaTarjeta.vue';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import type { PuestoJerarquiaItem } from '@/types';

const props = defineProps<{
    puesto: PuestoJerarquiaItem;
    hijos: PuestoJerarquiaItem[];
    obtenerHijos: (id: number) => PuestoJerarquiaItem[];
    nivel?: number;
}>();

const emit = defineEmits<{
    seleccionar: [puesto: PuestoJerarquiaItem];
    editar: [puesto: PuestoJerarquiaItem];
}>();

// Los dos primeros niveles inician expandidos para dar contexto inmediato
// sin obligar a tocar cada nodo; el resto del árbol arranca colapsado.
const abierto = ref((props.nivel ?? 0) < 2);
</script>

<template>
    <Collapsible v-model:open="abierto">
        <div class="flex items-start gap-2">
            <CollapsibleTrigger
                v-if="hijos.length"
                class="mt-4 flex size-7 shrink-0 items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground"
            >
                <ChevronRight
                    class="size-4 transition-transform duration-200"
                    :class="abierto && 'rotate-90'"
                />
            </CollapsibleTrigger>
            <div v-else class="w-7 shrink-0" />

            <OrganigramaTarjeta
                class="flex-1"
                :puesto="puesto"
                @seleccionar="emit('seleccionar', puesto)"
                @editar="emit('editar', puesto)"
            />
        </div>

        <CollapsibleContent
            v-if="hijos.length"
            class="mt-2 ml-4 flex flex-col gap-2 border-l border-border/60 py-1 pl-4"
        >
            <OrganigramaAccordion
                v-for="hijo in hijos"
                :key="hijo.id"
                :puesto="hijo"
                :hijos="obtenerHijos(hijo.id)"
                :obtener-hijos="obtenerHijos"
                :nivel="(nivel ?? 0) + 1"
                @seleccionar="(p) => emit('seleccionar', p)"
                @editar="(p) => emit('editar', p)"
            />
        </CollapsibleContent>
    </Collapsible>
</template>
