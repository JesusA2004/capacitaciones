<script setup lang="ts">
import { computed, useAttrs } from 'vue';

/**
 * Card de reemplazo para una fila de tabla en móvil: título + subtítulo +
 * badge de estado (slot `badge`) + datos clave (slot por defecto) +
 * acciones (slot `acciones`). Se usa dentro del slot `#mobile-card` de
 * DataTable.vue. Si el padre le pasa `@click`, la tarjeta completa abre
 * esa acción (sin obligar a usar el menú de puntitos); el pie de
 * acciones detiene la propagación para no disparar ambos a la vez.
 */
withDefaults(
    defineProps<{
        titulo: string;
        subtitulo?: string;
    }>(),
    {},
);

defineOptions({ inheritAttrs: true });

const attrs = useAttrs();
const esClicable = computed(() => attrs.onClick !== undefined);
</script>

<template>
    <div
        :class="[
            'flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-md',
            esClicable ? 'cursor-pointer active:bg-muted/40' : '',
        ]"
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
            @click.stop
        >
            <slot name="acciones" />
        </div>
    </div>
</template>
