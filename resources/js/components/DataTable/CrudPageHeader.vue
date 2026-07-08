<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Encabezado estándar de una pantalla de administración: título + una
 * frase que explica qué hace el módulo (para que el admin no tenga que
 * adivinarlo) + el botón principal a la derecha. Sustituye al uso suelto
 * de Heading.vue + Button en cada Index.vue.
 */
withDefaults(
    defineProps<{
        titulo: string;
        descripcion?: string;
        icono?: Component;
    }>(),
    {},
);
</script>

<template>
    <div
        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex items-start gap-3">
            <span
                v-if="icono"
                class="flex size-10 shrink-0 items-center justify-center rounded-2xl bg-primary/10 text-primary"
            >
                <component :is="icono" class="size-5" />
            </span>
            <div>
                <h2 class="text-xl font-semibold tracking-tight">
                    {{ titulo }}
                </h2>
                <p v-if="descripcion" class="text-sm text-muted-foreground">
                    {{ descripcion }}
                </p>
            </div>
        </div>

        <div
            v-if="$slots.default"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot />
        </div>
    </div>
</template>
