<script setup lang="ts">
/**
 * Card de reemplazo para una fila de tabla en móvil: título + subtítulo +
 * badge de estado (slot `badge`) + datos clave (slot por defecto) +
 * acciones (slot `acciones`). Se usa dentro del slot `#mobile-card` de
 * DataTable.vue.
 */
withDefaults(
    defineProps<{
        titulo: string;
        subtitulo?: string;
    }>(),
    {},
);
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-md"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="truncate font-medium">{{ titulo }}</p>
                <p
                    v-if="subtitulo"
                    class="truncate text-sm text-muted-foreground"
                >
                    {{ subtitulo }}
                </p>
            </div>
            <div v-if="$slots.badge" class="shrink-0">
                <slot name="badge" />
            </div>
        </div>

        <div
            v-if="$slots.default"
            class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground"
        >
            <slot />
        </div>

        <div
            v-if="$slots.acciones"
            class="flex items-center justify-end gap-1 border-t border-border/60 pt-2"
        >
            <slot name="acciones" />
        </div>
    </div>
</template>
