<script setup lang="ts">
import { SlidersHorizontal } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';

/**
 * Filtros adicionales (selects de sucursal/estatus/etc.) dentro de un Sheet
 * en vez de amontonarlos siempre visibles en la barra de herramientas —
 * más cómodo en móvil y menos saturado en escritorio. El botón que lo abre
 * muestra cuántos filtros están activos.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        titulo?: string;
        descripcion?: string;
        contadorActivos?: number;
    }>(),
    {
        titulo: 'Filtros',
        contadorActivos: 0,
    },
);

const emit = defineEmits<{
    'update:open': [valor: boolean];
    aplicar: [];
    limpiar: [];
}>();

function aplicarYCerrar() {
    emit('aplicar');
    emit('update:open', false);
}

function limpiarFiltros() {
    emit('limpiar');
    emit('update:open', false);
}
</script>

<template>
    <Sheet :open="open" @update:open="(valor) => emit('update:open', valor)">
        <SheetTrigger as-child>
            <Button variant="outline" size="sm" class="gap-2">
                <SlidersHorizontal class="size-4" />
                Filtros
                <Badge
                    v-if="contadorActivos > 0"
                    variant="secondary"
                    class="ml-0.5"
                    >{{ contadorActivos }}</Badge
                >
            </Button>
        </SheetTrigger>
        <SheetContent side="right" class="flex flex-col gap-4">
            <SheetHeader>
                <SheetTitle>{{ props.titulo }}</SheetTitle>
                <SheetDescription v-if="descripcion">{{
                    descripcion
                }}</SheetDescription>
            </SheetHeader>

            <div class="flex flex-1 flex-col gap-4 overflow-y-auto px-4">
                <slot />
            </div>

            <SheetFooter class="flex-row gap-2">
                <Button variant="outline" class="flex-1" @click="limpiarFiltros"
                    >Limpiar</Button
                >
                <Button class="flex-1" @click="aplicarYCerrar">Aplicar</Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
