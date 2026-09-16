<script setup lang="ts">
import type { Component } from 'vue';

defineProps<{
    icono?: Component;
    etiqueta: string;
    valor?: string | null;
}>();
</script>

<template>
    <div
        class="flex items-start gap-2.5 rounded-xl border border-border/50 bg-muted/30 px-3 py-2.5"
    >
        <component
            :is="icono"
            v-if="icono"
            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
        />
        <div class="min-w-0">
            <p class="text-xs text-muted-foreground">{{ etiqueta }}</p>
            <div class="truncate text-sm font-medium">
                <slot>{{ valor ?? '—' }}</slot>
            </div>
        </div>
    </div>
</template>
