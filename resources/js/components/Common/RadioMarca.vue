<script setup lang="ts" generic="T extends string | number | boolean">
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Radio con el look de shadcn que reemplaza a `<input type="radio">` sin
 * cambiar la estructura del formulario: `<RadioMarca v-model="x" :value="v" />`
 * dentro de su `<label>` de siempre.
 */
const props = defineProps<{
    modelValue: T | null | undefined;
    value: T;
    disabled?: boolean;
    id?: string;
    name?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [valor: T] }>();

const marcado = computed(() => props.modelValue === props.value);
</script>

<template>
    <button
        :id="id"
        type="button"
        role="radio"
        :aria-checked="marcado"
        :data-state="marcado ? 'checked' : 'unchecked'"
        :disabled="disabled"
        :class="
            cn(
                'grid aspect-square size-4 shrink-0 place-items-center rounded-full border border-input shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 dark:bg-input/30',
                marcado && 'border-primary',
            )
        "
        @click="emit('update:modelValue', value)"
    >
        <span v-if="marcado" class="size-2 rounded-full bg-primary" />
    </button>
</template>
