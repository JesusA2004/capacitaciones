<script setup lang="ts">
import OrganigramaTarjeta from '@/components/Administracion/OrganigramaTarjeta.vue';
import type { PuestoJerarquiaItem } from '@/types';

defineProps<{
    puesto: PuestoJerarquiaItem;
    hijos: PuestoJerarquiaItem[];
    obtenerHijos: (id: number) => PuestoJerarquiaItem[];
}>();

const emit = defineEmits<{
    seleccionar: [puesto: PuestoJerarquiaItem];
    editar: [puesto: PuestoJerarquiaItem];
}>();
</script>

<template>
    <div class="flex flex-col items-center">
        <OrganigramaTarjeta
            :puesto="puesto"
            compacto
            @seleccionar="emit('seleccionar', puesto)"
            @editar="emit('editar', puesto)"
        />

        <template v-if="hijos.length">
            <div class="h-6 w-px bg-border" />
            <div class="flex flex-wrap items-start justify-center gap-6">
                <div
                    v-for="hijo in hijos"
                    :key="hijo.id"
                    class="flex flex-col items-center"
                >
                    <div class="h-6 w-px bg-border" />
                    <OrganigramaNodo
                        :puesto="hijo"
                        :hijos="obtenerHijos(hijo.id)"
                        :obtener-hijos="obtenerHijos"
                        @seleccionar="(p) => emit('seleccionar', p)"
                        @editar="(p) => emit('editar', p)"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
