<script setup lang="ts">
import { Pencil, Route, Users } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { PuestoJerarquiaItem } from '@/types';

defineProps<{
    puesto: PuestoJerarquiaItem;
    /** true dentro del árbol de escritorio, donde el ancho es fijo. */
    compacto?: boolean;
}>();

const emit = defineEmits<{
    seleccionar: [];
    editar: [];
}>();

const TIPO_ETIQUETA: Record<string, string> = {
    comercial: 'Comercial',
    administrativo: 'Administrativo',
    operativo: 'Operativo',
    otro: 'Otro',
};
</script>

<template>
    <div
        class="group relative flex flex-col gap-2 rounded-2xl border border-border/60 bg-gradient-to-br from-card to-muted/30 p-4 text-left shadow-sm transition-all duration-200 ease-out hover:-translate-y-1 hover:border-primary/40 hover:shadow-xl"
        :class="compacto ? 'w-64' : 'w-full'"
    >
        <button
            type="button"
            class="flex flex-1 flex-col gap-2 text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            @click="emit('seleccionar')"
        >
            <div class="flex items-start justify-between gap-2">
                <span class="text-sm font-semibold break-words">{{
                    puesto.nombre
                }}</span>
                <Badge
                    v-if="puesto.nivel_jerarquico"
                    variant="outline"
                    class="shrink-0 text-[10px]"
                    >N{{ puesto.nivel_jerarquico }}</Badge
                >
            </div>

            <span class="text-xs text-muted-foreground">
                {{ puesto.departamento?.nombre ?? 'Sin departamento' }}
            </span>

            <div class="flex flex-wrap items-center gap-1.5">
                <Badge
                    v-if="!puesto.activo"
                    variant="outline"
                    class="border-muted-foreground/40 text-xs text-muted-foreground"
                    >Inactivo</Badge
                >
                <Badge
                    v-if="puesto.vacantes_abiertas_count > 0"
                    variant="warning"
                    class="text-xs"
                    >Vacante</Badge
                >
                <Badge
                    v-if="puesto.activo && puesto.usuarios_count === 0"
                    variant="destructive"
                    class="text-xs"
                    >Sin cobertura</Badge
                >
                <Badge
                    v-if="puesto.candidatos_count > 0"
                    variant="secondary"
                    class="text-xs"
                    >Candidatos</Badge
                >
                <Badge
                    v-if="puesto.tipo_puesto"
                    variant="outline"
                    class="text-xs"
                    >{{ TIPO_ETIQUETA[puesto.tipo_puesto] }}</Badge
                >
                <Badge
                    v-if="puesto.requiere_ruta"
                    variant="outline"
                    class="gap-1 text-xs"
                >
                    <Route class="size-3" />
                    Ruta
                </Badge>
            </div>

            <div
                class="mt-1 flex items-center gap-3 text-xs text-muted-foreground"
            >
                <span class="inline-flex items-center gap-1">
                    <Users class="size-3.5" />
                    {{ puesto.usuarios_count }}
                    {{ puesto.usuarios_count === 1 ? 'persona' : 'personas' }}
                </span>
            </div>
        </button>

        <button
            type="button"
            title="Editar jerarquía"
            class="absolute top-3 right-3 flex size-6 items-center justify-center rounded-lg text-muted-foreground opacity-100 transition-all duration-200 hover:bg-accent hover:text-accent-foreground md:opacity-0 md:group-hover:translate-x-0 md:group-hover:opacity-100"
            @click.stop="emit('editar')"
        >
            <Pencil class="size-3.5" />
        </button>
    </div>
</template>
