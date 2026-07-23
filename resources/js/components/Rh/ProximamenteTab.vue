<script setup lang="ts">
import { Clock } from '@lucide/vue';
import type { Component } from 'vue';

withDefaults(
    defineProps<{
        titulo: string;
        descripcion: string;
        icono?: Component;
    }>(),
    {
        icono: () => Clock,
    },
);
</script>

<template>
    <div
        class="flex flex-col items-center gap-3 rounded-2xl border border-dashed border-border/60 px-6 py-12 text-center"
    >
        <span
            class="flex size-10 items-center justify-center rounded-xl bg-muted text-muted-foreground"
        >
            <component :is="icono" class="size-5" />
        </span>
        <div>
            <p class="text-sm font-medium">{{ titulo }}</p>
            <p class="mt-1 max-w-sm text-xs text-muted-foreground">
                {{ descripcion }}
            </p>
        </div>
    </div>
</template>
