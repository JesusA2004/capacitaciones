<script setup lang="ts">
import { Loader2 } from '@lucide/vue';

/**
 * Interruptor encendido/apagado (role="switch") del catálogo de Recepción
 * documental. Accesible con teclado (Espacio / Enter) y con estado de
 * «guardando» para el encendido optimista.
 */
const props = withDefaults(
    defineProps<{
        modelValue: boolean;
        disabled?: boolean;
        guardando?: boolean;
        etiqueta: string;
    }>(),
    { disabled: false, guardando: false },
);

const emit = defineEmits<{ 'update:modelValue': [valor: boolean] }>();

function alternar() {
    if (!props.disabled && !props.guardando) {
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
        class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer items-center rounded-full border transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
        :class="
            modelValue ? 'border-primary bg-primary' : 'border-border bg-muted'
        "
        @click="alternar"
    >
        <span
            class="pointer-events-none flex size-5 items-center justify-center rounded-full bg-card shadow-sm transition-transform duration-200 ease-out motion-reduce:transition-none"
            :class="modelValue ? 'translate-x-5' : 'translate-x-0.5'"
        >
            <Loader2
                v-if="guardando"
                class="size-3 animate-spin text-muted-foreground"
            />
        </span>
    </button>
</template>
