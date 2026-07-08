<script setup lang="ts">
/**
 * Encabezado de una línea + grid responsive para agrupar KPIs/gráficas
 * relacionadas dentro de un dashboard, para que cada bloque explique en una
 * frase qué se está mostrando (en vez de que el admin tenga que adivinar).
 */
withDefaults(
    defineProps<{
        titulo: string;
        descripcion?: string;
        columnas?: 1 | 2 | 3 | 4;
    }>(),
    {
        columnas: 3,
    },
);

const COLUMNAS: Record<number, string> = {
    1: '',
    2: 'sm:grid-cols-2',
    3: 'sm:grid-cols-2 xl:grid-cols-3',
    4: 'sm:grid-cols-2 xl:grid-cols-4',
};
</script>

<template>
    <section class="flex flex-col gap-3">
        <header>
            <h3 class="text-sm font-semibold tracking-tight">{{ titulo }}</h3>
            <p v-if="descripcion" class="text-xs text-muted-foreground">
                {{ descripcion }}
            </p>
        </header>

        <div class="grid grid-cols-1 gap-4" :class="COLUMNAS[columnas]">
            <slot />
        </div>
    </section>
</template>
