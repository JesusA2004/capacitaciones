<script setup lang="ts" generic="T extends string | number | null">
import { computed } from 'vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

/**
 * Select de shadcn listo para formularios: reemplaza al `<select>` nativo
 * (que abre la lista fea del navegador). Acepta string/number/null en el
 * v-model y una opción "vacía" opcional — el Select de reka no admite el
 * valor '' en un item, así que se traduce aquí.
 */
const props = withDefaults(
    defineProps<{
        modelValue: T | undefined;
        opciones: { value: string | number; label: string }[];
        placeholder?: string;
        /** Texto de la opción que deja el campo vacío (p. ej. "Todas"). */
        opcionVacia?: string;
        id?: string;
        disabled?: boolean;
        class?: string;
        size?: 'sm' | 'default';
        ariaLabel?: string;
        /** Emite number (o null si queda vacío) en vez de string: para ids. */
        numerico?: boolean;
    }>(),
    { placeholder: 'Selecciona…', size: 'default' },
);

const emit = defineEmits<{
    'update:modelValue': [valor: T];
}>();

const VACIO = '__vacio__';

const interno = computed(() =>
    props.modelValue === null ||
    props.modelValue === undefined ||
    props.modelValue === ''
        ? props.opcionVacia
            ? VACIO
            : undefined
        : String(props.modelValue),
);

function alCambiar(valor: unknown) {
    const vacio = valor === VACIO || valor === null || valor === undefined;

    if (props.numerico) {
        emit('update:modelValue', (vacio ? null : Number(valor)) as T);

        return;
    }

    emit('update:modelValue', (vacio ? '' : String(valor)) as T);
}
</script>

<template>
    <Select
        :model-value="interno"
        :disabled="disabled"
        @update:model-value="alCambiar"
    >
        <SelectTrigger
            :id="id"
            :size="size"
            :class="['w-full', props.class]"
            :aria-label="ariaLabel"
        >
            <SelectValue :placeholder="placeholder" />
        </SelectTrigger>
        <SelectContent>
            <SelectItem v-if="opcionVacia" :value="VACIO">{{
                opcionVacia
            }}</SelectItem>
            <SelectItem
                v-for="o in opciones"
                :key="o.value"
                :value="String(o.value)"
                >{{ o.label }}</SelectItem
            >
        </SelectContent>
    </Select>
</template>
