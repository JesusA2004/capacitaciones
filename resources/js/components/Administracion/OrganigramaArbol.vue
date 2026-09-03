<script setup lang="ts">
import { Minus, Plus, RotateCcw } from '@lucide/vue';
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
}>();

const zoom = ref(1);

function acercar() {
    zoom.value = Math.min(1.4, Math.round((zoom.value + 0.1) * 10) / 10);
}

function alejar() {
    zoom.value = Math.max(0.6, Math.round((zoom.value - 0.1) * 10) / 10);
}

function restablecer() {
    zoom.value = 1;
}
</script>

<template>
    <div class="relative rounded-2xl border border-border/60 bg-muted/20">
        <div
            class="absolute top-3 right-3 z-10 flex gap-1 rounded-lg border border-border/60 bg-card/95 p-1 shadow-sm backdrop-blur"
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
                size="icon"
                class="size-7"
                title="Restablecer zoom"
                @click="restablecer"
            >
                <RotateCcw class="size-3.5" />
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

        <div class="overflow-x-auto p-6">
            <div
                class="flex w-max min-w-full origin-top flex-wrap items-start justify-center gap-10 transition-transform duration-200 ease-out"
                :style="{ transform: `scale(${zoom})` }"
            >
                <OrganigramaNodo
                    v-for="raiz in raices"
                    :key="raiz.id"
                    :puesto="raiz"
                    :hijos="obtenerHijos(raiz.id)"
                    :obtener-hijos="obtenerHijos"
                    @seleccionar="(p) => emit('seleccionar', p)"
                    @editar="(p) => emit('editar', p)"
                />
            </div>
        </div>
    </div>
</template>
