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
        <span
            class="mb-1 flex size-16 items-center justify-center rounded-3xl bg-crema text-bronce"
            aria-hidden="true"
        >
            <component :is="icono" class="size-8" />
        </span>
        <p class="text-base font-semibold">{{ titulo }}</p>
        <p class="max-w-md text-sm text-pretty text-muted-foreground">
            {{ descripcion }}
        </p>
        <div v-if="$slots.default" class="mt-2">
            <slot />
        </div>
    </div>
</template>
