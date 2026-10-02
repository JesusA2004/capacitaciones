<script setup lang="ts">
import { CircleHelp } from '@lucide/vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/**
 * Botón "?" junto a una opción: explica en lenguaje sencillo qué hace o
 * para qué sirve. Se abre con clic (también en pantallas táctiles).
 */
defineProps<{
    titulo?: string;
    texto: string;
}>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="inline-flex size-5 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-label="titulo ? `¿Qué es «${titulo}»?` : 'Ayuda'"
                @click.stop
            >
                <CircleHelp class="size-4" />
            </button>
        </PopoverTrigger>
        <PopoverContent class="w-72 text-sm" align="start">
            <p v-if="titulo" class="mb-1 font-medium">{{ titulo }}</p>
            <p class="text-muted-foreground">{{ texto }}</p>
        </PopoverContent>
    </Popover>
</template>
