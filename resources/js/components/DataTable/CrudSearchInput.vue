<script setup lang="ts">
import { Search } from '@lucide/vue';
import { Input } from '@/components/ui/input';

/**
 * El buscador con icono, extraído de TableFilters.vue para poder
 * componerlo dentro de CrudToolbar junto con el botón de filtros
 * avanzados en CrudFilterSheet.
 */
withDefaults(
    defineProps<{
        modelValue: string;
        placeholder?: string;
    }>(),
    {},
);

const emit = defineEmits<{
    'update:modelValue': [valor: string];
}>();
</script>

<template>
    <div class="relative w-full sm:max-w-xs">
        <Search
            class="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground"
        />
        <Input
            :model-value="modelValue"
            @update:model-value="
                (valor) => emit('update:modelValue', String(valor))
            "
            :placeholder="placeholder ?? 'Buscar...'"
            class="pl-8"
        />
    </div>
</template>
