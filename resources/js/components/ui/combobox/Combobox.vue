<script setup lang="ts">
import { computed } from 'vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionCombobox } from './filtro';

/**
 * Adaptador de compatibilidad: el select con búsqueda del sistema es
 * `ComboboxBuscable` (ui/combobox-buscable) — aquí solo se traduce la API
 * anterior (`items`, valor string con '' = vacío, `clearable`,
 * `emptyText`, `palabrasClave`) para no tener dos implementaciones.
 * Código nuevo: usa `ComboboxBuscable` (o `SelectSimple`) directamente.
 */
const props = withDefaults(
    defineProps<{
        items?: OpcionCombobox[];
        modelValue: string;
        placeholder?: string;
        placeholderBusqueda?: string;
        emptyText?: string;
        class?: string;
        id?: string;
        ariaLabel?: string;
        disabled?: boolean;
        size?: 'sm' | 'default';
        clearable?: boolean;
        limite?: number;
        buscar?: (termino: string) => Promise<OpcionCombobox[]>;
        etiquetaSeleccionada?: string | null;
    }>(),
    {
        items: () => [],
        placeholder: 'Selecciona…',
        placeholderBusqueda: 'Buscar…',
        emptyText: 'Sin coincidencias.',
        size: 'default',
        clearable: true,
        limite: 200,
        buscar: undefined,
        etiquetaSeleccionada: null,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
    seleccionar: [opcion: OpcionCombobox | null];
}>();

function aBuscable(o: OpcionCombobox): OpcionBuscable {
    return {
        value: o.value,
        label: o.label,
        descripcion: o.descripcion ?? undefined,
        grupo: o.grupo ?? undefined,
        alias: o.palabrasClave ? [o.palabrasClave] : undefined,
        deshabilitado: o.disabled,
    };
}

function deBuscable(o: OpcionBuscable): OpcionCombobox {
    return {
        value: String(o.value),
        label: o.label,
        descripcion: o.descripcion,
        grupo: o.grupo,
        palabrasClave: o.alias?.join(' '),
        disabled: o.deshabilitado,
    };
}

const opciones = computed(() => props.items.map(aBuscable));

const buscarRemoto = computed(() => {
    const buscar = props.buscar;

    return buscar
        ? async (termino: string) => (await buscar(termino)).map(aBuscable)
        : undefined;
});
</script>

<template>
    <ComboboxBuscable
        :id="id"
        :model-value="modelValue === '' ? null : modelValue"
        :opciones="opciones"
        :placeholder="placeholder"
        :placeholder-busqueda="placeholderBusqueda"
        :texto-vacio="emptyText"
        :limpiable="clearable"
        :disabled="disabled"
        :size="size"
        :aria-label="ariaLabel"
        :class="props.class"
        :limite="limite"
        :buscar="buscarRemoto"
        :etiqueta-seleccionada="etiquetaSeleccionada"
        @update:model-value="(v) => emit('update:modelValue', v === null || v === undefined ? '' : String(v))"
        @seleccionar="(o) => emit('seleccionar', o ? deBuscable(o) : null)"
    />
</template>
