<script setup lang="ts">
import { computed, ref } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Importe en pesos (MXN): muestra el monto con separadores de miles
 * («$18,500.00») y al enfocarlo deja editar el número limpio. El v-model es
 * un número (o null si queda vacío), nunca un string con comas.
 */
const props = withDefaults(
    defineProps<{
        modelValue: number | null;
        id?: string;
        placeholder?: string;
        disabled?: boolean;
        invalido?: boolean;
        ariaLabel?: string;
        class?: string;
    }>(),
    { placeholder: '0.00' },
);

const emit = defineEmits<{
    'update:modelValue': [valor: number | null];
    blur: [];
}>();

const enfocado = ref(false);
const borrador = ref('');

const formato = new Intl.NumberFormat('es-MX', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const texto = computed(() => {
    if (enfocado.value) {
        return borrador.value;
    }

    return props.modelValue === null ? '' : formato.format(props.modelValue);
});

function alEnfocar(): void {
    borrador.value = props.modelValue === null ? '' : String(props.modelValue);
    enfocado.value = true;
}

function alEscribir(evento: Event): void {
    const limpio = (evento.target as HTMLInputElement).value
        .replace(/[^\d.]/g, '')
        .replace(/(\..*)\./g, '$1');
    borrador.value = limpio;
    const numero = Number.parseFloat(limpio);
    emit('update:modelValue', Number.isFinite(numero) ? numero : null);
}

function alSalir(): void {
    enfocado.value = false;

    if (props.modelValue !== null) {
        emit('update:modelValue', Math.round(props.modelValue * 100) / 100);
    }

    emit('blur');
}
</script>

<template>
    <div
        :class="
            cn(
                'group relative flex h-11 items-center rounded-xl border bg-background/70 transition-[border-color,box-shadow] focus-within:border-primary/50 focus-within:ring-4 focus-within:ring-primary/10',
                invalido &&
                    'border-destructive focus-within:ring-destructive/15',
                disabled && 'pointer-events-none opacity-60',
                props.class,
            )
        "
    >
        <span
            class="pointer-events-none pl-3 text-sm font-medium text-muted-foreground"
            aria-hidden="true"
            >$</span
        >
        <input
            :id="id"
            :value="texto"
            type="text"
            inputmode="decimal"
            autocomplete="off"
            :placeholder="placeholder"
            :disabled="disabled"
            :aria-label="ariaLabel"
            :aria-invalid="invalido || undefined"
            class="h-full w-full min-w-0 bg-transparent px-2 text-right text-base font-semibold tabular-nums outline-none placeholder:font-normal placeholder:text-muted-foreground"
            @focus="alEnfocar"
            @input="alEscribir"
            @blur="alSalir"
        />
        <span
            class="pointer-events-none pr-3 text-xs text-muted-foreground"
            aria-hidden="true"
            >MXN</span
        >
    </div>
</template>
