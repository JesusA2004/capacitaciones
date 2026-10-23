<script setup lang="ts" generic="T extends string | number | null">
import { computed } from 'vue';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { separarClasesAcomodo } from '@/lib/clasesAcomodo';

/**
 * Select de shadcn listo para formularios: reemplaza al `<select>` nativo
 * (que abre la lista fea del navegador). Acepta string/number/null en el
 * v-model y una opción "vacía" opcional — el Select de reka no admite el
 * valor '' en un item, así que se traduce aquí.
 *
 * Con MÁS de 7 opciones (sucursales, puestos, colaboradores, catálogos)
 * se vuelve un select CON BÚSQUEDA (`ComboboxBuscable`): lista completa al abrir,
 * filtra sin acentos y por palabras sobre etiqueta + `descripcion`.
 * `buscable` lo fuerza (true) o lo apaga (false).
 */
const props = withDefaults(
    defineProps<{
        modelValue: T | undefined;
        opciones: {
            value: string | number;
            label: string;
            /** Línea secundaria (y buscable) en el modo con búsqueda. */
            descripcion?: string | null;
            grupo?: string | null;
            palabrasClave?: string | null;
            disabled?: boolean;
        }[];
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
        /** Select con búsqueda: 'auto' = solo si hay más de 7 opciones. */
        buscable?: boolean | 'auto';
        placeholderBusqueda?: string;
    }>(),
    { placeholder: 'Selecciona…', size: 'default', buscable: 'auto' },
);

const emit = defineEmits<{
    'update:modelValue': [valor: T];
}>();

const VACIO = '__vacio__';
const UMBRAL_BUSQUEDA = 7;

const conBusqueda = computed(() =>
    props.buscable === 'auto'
        ? props.opciones.length > UMBRAL_BUSQUEDA
        : props.buscable,
);

const interno = computed(() =>
    props.modelValue === null ||
    props.modelValue === undefined ||
    props.modelValue === ''
        ? props.opcionVacia
            ? VACIO
            : undefined
        : String(props.modelValue),
);

/**
 * En el modo con búsqueda, `class` se reparte: lo que acomoda el campo en
 * su contenedor (ancho, flex, márgenes, grid) va a la RAÍZ del combobox y
 * el botón la llena; lo visual (alto, texto, fondo, borde) va al BOTÓN.
 * Antes iba completo a los dos y un ancho en % se aplicaba dos veces.
 */
const clasesCombobox = computed(() => {
    const { acomodo, resto } = separarClasesAcomodo(props.class);

    return { raiz: acomodo, boton: resto };
});

const opcionesBusqueda = computed<OpcionBuscable[]>(() => [
    ...(props.opcionVacia ? [{ value: VACIO, label: props.opcionVacia }] : []),
    ...props.opciones.map((o) => ({
        value: String(o.value),
        label: o.label,
        descripcion: o.descripcion ?? undefined,
        grupo: o.grupo ?? undefined,
        alias: o.palabrasClave ? [o.palabrasClave] : undefined,
        deshabilitado: o.disabled,
    })),
]);

function alCambiar(valor: unknown) {
    const vacio =
        valor === VACIO ||
        valor === null ||
        valor === undefined ||
        valor === '';

    if (props.numerico) {
        emit('update:modelValue', (vacio ? null : Number(valor)) as T);

        return;
    }

    emit('update:modelValue', (vacio ? '' : String(valor)) as T);
}
</script>

<template>
    <ComboboxBuscable
        v-if="conBusqueda"
        :id="id"
        :opciones="opcionesBusqueda"
        :model-value="interno ?? null"
        :placeholder="placeholder"
        :placeholder-busqueda="placeholderBusqueda"
        :disabled="disabled"
        :size="size"
        :aria-label="ariaLabel"
        :limpiable="!!opcionVacia && interno !== VACIO"
        :class="clasesCombobox.raiz"
        :clase-boton="clasesCombobox.boton"
        @update:model-value="alCambiar"
    />
    <Select
        v-else
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
                :disabled="o.disabled"
                >{{ o.label }}</SelectItem
            >
        </SelectContent>
    </Select>
</template>
