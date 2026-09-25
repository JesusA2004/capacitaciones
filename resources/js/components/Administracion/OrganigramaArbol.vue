<script setup lang="ts">
import { Minus, Plus } from '@lucide/vue';
import { ref } from 'vue';
import OrganigramaNodo from '@/components/Administracion/OrganigramaNodo.vue';
import { Button } from '@/components/ui/button';
import type { PuestoJerarquiaItem } from '@/types';

defineProps<{
    raices: PuestoJerarquiaItem[];
    obtenerHijos: (id: number) => PuestoJerarquiaItem[];
}>();

const emit = defineEmits<{
    seleccionar: [puesto: PuestoJerarquiaItem];
    editar: [puesto: PuestoJerarquiaItem];
    agregarSubordinado: [puesto: PuestoJerarquiaItem];
    quitarRelacion: [puesto: PuestoJerarquiaItem];
}>();

const zoom = ref(1);

function acercar() {
    zoom.value = Math.min(1.4, Math.round((zoom.value + 0.1) * 10) / 10);
}

function alejar() {
    zoom.value = Math.max(0.3, Math.round((zoom.value - 0.1) * 10) / 10);
}

function restablecer() {
    zoom.value = 1;
}
</script>

<template>
    <div class="relative rounded-2xl border border-border/60 bg-muted/20">
        <div
            class="absolute top-4 right-4 z-10 flex items-center gap-1 rounded-xl border border-border/60 bg-card/95 p-1 shadow-md backdrop-blur"
        >
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                title="Alejar"
                @click="alejar"
            >
                <Minus class="size-3.5" />
            </Button>
            <Button
                variant="ghost"
                title="Restablecer zoom"
                class="h-7 w-auto px-2"
                @click="restablecer"
            >
                <span class="text-xs font-semibold tabular-nums"
                    >{{ Math.round(zoom * 100) }}%</span
                >
            </Button>
            <Button
                variant="ghost"
                size="icon"
                class="size-7"
                title="Acercar"
                @click="acercar"
            >
                <Plus class="size-3.5" />
            </Button>
        </div>

        <!-- Alto acotado con scroll en las dos direcciones DENTRO de esta
             caja: un árbol grande ya no obliga a bajar hasta el final de la
             página para encontrar la barra horizontal — queda siempre a la
             vista, pegada al borde de este panel. -->
        <div class="max-h-[70vh] overflow-auto p-6">
            <div
                class="flex w-max min-w-full flex-wrap items-start justify-center gap-8"
                :style="{ zoom }"
            >
                <OrganigramaNodo
                    v-for="raiz in raices"
                    :key="raiz.id"
                    :puesto="raiz"
                    :hijos="obtenerHijos(raiz.id)"
                    :obtener-hijos="obtenerHijos"
                    @seleccionar="(p) => emit('seleccionar', p)"
                    @editar="(p) => emit('editar', p)"
                    @agregar-subordinado="(p) => emit('agregarSubordinado', p)"
                    @quitar-relacion="(p) => emit('quitarRelacion', p)"
                />
            </div>
        </div>
    </div>
</template>
