<script setup lang="ts">
import { computed, useId } from 'vue';
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import type { PorPagina } from '@/composables/usePaginacion';
import {
    normalizarPorPagina,
    OPCIONES_POR_PAGINA,
    VALOR_TODOS,
} from '@/composables/usePaginacion';

/**
 * «Mostrar [20 ▼] por página». Ofrece 10 · 20 · 30 · 40 · 50 · Todos; si
 * el listado trae otro tamaño por defecto (p. ej. 24 tarjetas), también lo
 * muestra para que el valor seleccionado nunca quede en blanco.
 */
const props = defineProps<{
    modelValue: PorPagina;
}>();

const emit = defineEmits<{ 'update:modelValue': [valor: PorPagina] }>();

const id = useId();

const opciones = computed<number[]>(() => {
    const base: number[] = [...OPCIONES_POR_PAGINA];

    if (
        typeof props.modelValue === 'number' &&
        !base.includes(props.modelValue)
    ) {
        base.push(props.modelValue);
        base.sort((a, b) => a - b);
    }

    return base;
});

function alCambiar(valor: unknown) {
    const normalizado =
        normalizarPorPagina(valor) ??
        (opciones.value.includes(Number(valor)) ? Number(valor) : null);

    if (normalizado !== null && normalizado !== props.modelValue) {
        emit('update:modelValue', normalizado);
    }
}
</script>

<template>
    <div class="inline-flex items-center gap-2 text-sm text-muted-foreground">
        <label :for="id">Mostrar</label>
        <NativeSelect
            :id="id"
            :model-value="String(modelValue)"
            class="h-10 min-w-[5.5rem] rounded-lg bg-card py-1 pr-8 pl-2.5 text-sm text-foreground sm:h-8"
            @update:model-value="alCambiar"
        >
            <NativeSelectOption
                v-for="o in opciones"
                :key="o"
                :value="String(o)"
            >
                {{ o }}
            </NativeSelectOption>
            <NativeSelectOption :value="VALOR_TODOS">Todos</NativeSelectOption>
        </NativeSelect>
        <span>por página</span>
    </div>
</template>
