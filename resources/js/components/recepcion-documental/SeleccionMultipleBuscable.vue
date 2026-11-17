<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';

/**
 * Selección de varios elementos con buscador (sucursales, empresas): el
 * select buscable muestra la lista COMPLETA al enfocar, filtra al escribir y
 * cada elegido queda como chip que se quita con la «x». Vacío = «todas».
 */
const props = withDefaults(
    defineProps<{
        modelValue: number[];
        opciones: OpcionBuscable[];
        placeholder: string;
        textoTodos: string;
        disabled?: boolean;
        /** Nombre accesible del buscador («Agregar sucursal»). */
        nombreCampo: string;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{ 'update:modelValue': [valor: number[]] }>();

const seleccion = ref<number | null>(null);

const disponibles = computed<OpcionBuscable[]>(() =>
    props.opciones.map((o) => ({
        ...o,
        deshabilitado: props.modelValue.includes(Number(o.value)),
        nota: props.modelValue.includes(Number(o.value))
            ? 'Ya elegida'
            : undefined,
    })),
);

const elegidas = computed(() =>
    props.modelValue.map((id) => ({
        id,
        etiqueta:
            props.opciones.find((o) => Number(o.value) === id)?.label ??
            `#${id}`,
    })),
);

function agregar(valor: number | null) {
    if (valor !== null && !props.modelValue.includes(valor)) {
        emit('update:modelValue', [...props.modelValue, valor]);
    }

    seleccion.value = null;
}

function quitar(id: number) {
    emit(
        'update:modelValue',
        props.modelValue.filter((x) => x !== id),
    );
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <ComboboxBuscable
            :model-value="seleccion"
            :opciones="disponibles"
            :placeholder="placeholder"
            :aria-label="nombreCampo"
            :disabled="disabled"
            numerico
            size="sm"
            @update:model-value="agregar"
        />
        <ListaAnimada
            tag="ul"
            :cantidad="elegidas.length"
            class="flex flex-wrap gap-1.5"
        >
            <li
                v-for="e in elegidas"
                :key="e.id"
                class="inline-flex items-center gap-1 rounded-full border border-border/70 bg-salvia/60 py-0.5 pr-1 pl-2.5 text-xs"
            >
                {{ e.etiqueta }}
                <button
                    type="button"
                    class="flex size-5 items-center justify-center rounded-full transicion-ui hover:bg-card disabled:opacity-50"
                    :aria-label="`Quitar ${e.etiqueta}`"
                    :disabled="disabled"
                    @click="quitar(e.id)"
                >
                    <X class="size-3" />
                </button>
            </li>
            <li
                v-if="!elegidas.length"
                key="todos"
                class="text-xs text-muted-foreground"
            >
                {{ textoTodos }}
            </li>
        </ListaAnimada>
    </div>
</template>
