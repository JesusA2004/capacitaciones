<script setup lang="ts">
import { ref } from 'vue';
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
    agregarSubordinado: [puesto: PuestoJerarquiaItem];
    quitarRelacion: [puesto: PuestoJerarquiaItem];
}>();

const colapsado = ref(false);
</script>

<template>
    <div class="flex flex-col items-center">
        <OrganigramaTarjeta
            :puesto="puesto"
            compacto
            :tiene-hijos="hijos.length > 0"
            :colapsado="colapsado"
            @seleccionar="emit('seleccionar', puesto)"
            @editar="emit('editar', puesto)"
            @agregar-subordinado="emit('agregarSubordinado', puesto)"
            @quitar-relacion="emit('quitarRelacion', puesto)"
            @alternar-colapso="colapsado = !colapsado"
        />

        <template v-if="hijos.length && !colapsado">
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
                        @agregar-subordinado="(p) => emit('agregarSubordinado', p)"
                        @quitar-relacion="(p) => emit('quitarRelacion', p)"
                    />
                </div>
            </div>
        </template>
        <p
            v-else-if="hijos.length && colapsado"
            class="mt-2 text-[11px] text-muted-foreground"
        >
            {{ hijos.length }} subordinado{{ hijos.length === 1 ? '' : 's' }} ocultos
        </p>
    </div>
</template>
