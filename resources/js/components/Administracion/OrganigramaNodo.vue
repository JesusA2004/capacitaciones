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
            <!-- Tronco: del puesto hacia la barra horizontal que conecta a
                 todos sus subordinados (patrón clásico de organigrama, no
                 solo líneas sueltas por hijo). -->
            <div class="h-6 w-px bg-[var(--brand-primary)]/40" />
            <div class="flex flex-wrap items-start justify-center">
                <div
                    v-for="(hijo, indice) in hijos"
                    :key="hijo.id"
                    class="relative flex flex-col items-center px-5"
                >
                    <!-- Barra horizontal: solo la mitad para el primero/
                         último (para que no sobresalga del árbol), completa
                         para los del medio — junto con las de los demás
                         hermanos arma una sola línea continua. -->
                    <div
                        v-if="hijos.length > 1"
                        class="absolute top-0 h-px bg-[var(--brand-primary)]/40"
                        :class="[
                            indice === 0
                                ? 'right-0 left-1/2'
                                : indice === hijos.length - 1
                                  ? 'right-1/2 left-0'
                                  : 'inset-x-0',
                        ]"
                    />
                    <div class="h-6 w-px bg-[var(--brand-primary)]/40" />
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
            {{ hijos.length }} puesto{{ hijos.length === 1 ? '' : 's' }} debajo, oculto{{ hijos.length === 1 ? '' : 's' }}
        </p>
    </div>
</template>
