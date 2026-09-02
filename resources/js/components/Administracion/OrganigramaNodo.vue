<script setup lang="ts">
import { Users } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { PuestoJerarquiaItem } from '@/types';

defineProps<{
    puesto: PuestoJerarquiaItem;
    hijos: PuestoJerarquiaItem[];
    obtenerHijos: (id: number) => PuestoJerarquiaItem[];
}>();

const emit = defineEmits<{
    seleccionar: [puesto: PuestoJerarquiaItem];
}>();
</script>

<template>
    <div class="flex flex-col items-center">
        <button
            type="button"
            class="flex min-w-48 flex-col gap-1 rounded-2xl border border-border/60 bg-card px-4 py-3 text-left shadow-sm transition-colors hover:border-primary/40"
            @click="emit('seleccionar', puesto)"
        >
            <span class="text-sm font-semibold">{{ puesto.nombre }}</span>
            <span class="text-xs text-muted-foreground">
                {{ puesto.departamento?.nombre ?? 'Sin departamento' }}
            </span>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <Badge v-if="!puesto.activo" variant="outline" class="text-xs"
                    >Inactivo</Badge
                >
                <Badge
                    v-if="puesto.requiere_ruta"
                    variant="outline"
                    class="text-xs"
                    >Requiere ruta</Badge
                >
                <span
                    class="inline-flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <Users class="size-3" />
                    {{ puesto.usuarios_count }}
                </span>
            </div>
        </button>

        <template v-if="hijos.length">
            <div class="h-4 w-px bg-border" />
            <div class="flex flex-wrap items-start justify-center gap-6">
                <div
                    v-for="hijo in hijos"
                    :key="hijo.id"
                    class="flex flex-col items-center"
                >
                    <div class="h-4 w-px bg-border" />
                    <OrganigramaNodo
                        :puesto="hijo"
                        :hijos="obtenerHijos(hijo.id)"
                        :obtener-hijos="obtenerHijos"
                        @seleccionar="(p) => emit('seleccionar', p)"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
