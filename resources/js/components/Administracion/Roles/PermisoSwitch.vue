<script setup lang="ts">
/**
 * Interruptor accesible (role="switch") para un permiso de la matriz de
 * «Roles y permisos». Solo tokens del tema claro.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        disabled?: boolean;
        etiqueta: string;
        tamano?: 'sm' | 'md';
    }>(),
    { disabled: false, tamano: 'md' },
);

const emit = defineEmits<{ 'update:modelValue': [valor: boolean] }>();

function alternar() {
    if (!props.disabled) {
        emit('update:modelValue', !props.modelValue);
    }
}
</script>

<template>
    <button
        type="button"
        role="switch"
        :aria-checked="modelValue"
        :aria-label="etiqueta"
        :disabled="disabled"
        class="relative inline-flex shrink-0 items-center rounded-full border transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
        :class="[
            tamano === 'sm' ? 'h-5 w-9' : 'h-6 w-11',
            modelValue ? 'border-primary bg-primary' : 'border-border bg-muted',
        ]"
        @click.stop="alternar"
    >
        <span
            class="inline-block rounded-full bg-background shadow-sm transition-transform"
            :class="[
                tamano === 'sm' ? 'size-3.5' : 'size-4.5',
                modelValue
                    ? tamano === 'sm'
                        ? 'translate-x-[1.1rem]'
                        : 'translate-x-[1.35rem]'
                    : 'translate-x-0.5',
            ]"
        />
    </button>
</template>
