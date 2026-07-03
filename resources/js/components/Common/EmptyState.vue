<script setup lang="ts">
import { Inbox } from '@lucide/vue';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        icono?: Component;
        titulo?: string;
        descripcion?: string;
    }>(),
    {
        icono: () => Inbox,
        titulo: 'Sin resultados',
        descripcion: 'No hay información para mostrar todavía.',
    },
);
</script>

<template>
    <div
        class="flex flex-col items-center justify-center gap-2 px-6 py-12 text-center"
    >
        <component :is="icono" class="size-8 text-muted-foreground" />
        <p class="text-sm font-medium">{{ titulo }}</p>
        <p class="text-sm text-muted-foreground">{{ descripcion }}</p>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
