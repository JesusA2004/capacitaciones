<script setup lang="ts">
import { MoreVertical } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

/**
 * Acciones de una fila agrupadas en un menú, en vez de una fila de iconos
 * sueltos — más compacto en la tabla y el mismo componente sirve para el
 * pie de una CrudMobileCard.
 */
withDefaults(
    defineProps<{
        etiqueta?: string;
    }>(),
    {
        etiqueta: 'Acciones',
    },
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" :title="etiqueta">
                <MoreVertical class="size-4" />
                <span class="sr-only">{{ etiqueta }}</span>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
            <slot />
        </DropdownMenuContent>
    </DropdownMenu>
</template>
