<script setup lang="ts" generic="T extends string | number">
import { computed } from 'vue';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Checkbox de shadcn que reemplaza a `<input type="checkbox">`:
 * - v-model booleano: `<Casilla v-model="form.activa" />`
 * - v-model de lista con `value` (igual que el nativo):
 *   `<Casilla v-model="form.destinatarios" :value="t.value" />`
 * Funciona dentro de un `<label>` existente (el botón es "labelable").
 */
const props = defineProps<{
    modelValue: boolean | T[] | null | undefined;
    value?: T;
    disabled?: boolean;
    id?: string;
    ariaLabel?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [valor: boolean | T[]] }>();

const marcado = computed(() =>
    Array.isArray(props.modelValue)
        ? props.value !== undefined && props.modelValue.includes(props.value)
        : Boolean(props.modelValue),
);

function cambiar(valor: boolean | 'indeterminate') {
    const si = valor === true;

    if (Array.isArray(props.modelValue) && props.value !== undefined) {
        const sinEste = props.modelValue.filter((v) => v !== props.value);
        emit('update:modelValue', si ? [...sinEste, props.value] : sinEste);

        return;
    }

    emit('update:modelValue', si);
}
</script>

<template>
    <Checkbox
        :id="id"
        :model-value="marcado"
        :disabled="disabled"
        :aria-label="ariaLabel"
        @update:model-value="cambiar"
    />
</template>
