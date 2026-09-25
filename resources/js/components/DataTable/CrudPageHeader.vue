<script setup lang="ts">
import type { Component } from 'vue';

/**
 * Barra superior de una pantalla. En los módulos (listados, tableros) NO
 * se muestra título ni descripción — el breadcrumb del encabezado ya dice
 * dónde estás y el espacio vertical se deja para el contenido —; solo
 * quedan los botones de acción, alineados a la derecha. Si no hay botones,
 * no se dibuja nada.
 *
 * En pantallas de DETALLE (`detalle`), el título sí es información (folio
 * de la solicitud, nombre del candidato…) y se muestra.
 *
 * `titulo`/`descripcion`/`icono` se siguen recibiendo en módulos para
 * accesibilidad (aria-label) y para no romper las pantallas existentes.
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        descripcion?: string;
        icono?: Component;
        detalle?: boolean;
    }>(),
    { descripcion: undefined, icono: undefined, detalle: false },
);
</script>

<template>
    <div
        v-if="props.detalle"
        data-tour="encabezado"
        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex min-w-0 items-start gap-3">
            <span
                v-if="icono"
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/15 to-primary/5 text-primary shadow-sm ring-1 ring-primary/10"
            >
                <component :is="icono" class="size-5" />
            </span>
            <div class="min-w-0">
                <h2 class="truncate text-2xl font-semibold tracking-tight">
                    {{ titulo }}
                </h2>
                <p
                    v-if="descripcion"
                    class="mt-0.5 text-[15px] text-pretty text-muted-foreground"
                >
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

    <div
        v-else-if="$slots.default"
        data-tour="encabezado"
        role="toolbar"
        :aria-label="titulo"
        class="flex flex-wrap items-center justify-end gap-2"
    >
        <slot />
    </div>
</template>
