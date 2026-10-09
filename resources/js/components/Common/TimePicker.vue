<script setup lang="ts">
import { computed } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';

/**
 * Hora en español (formato «7:15 a. m.», cada 15 min) con el Select de
 * shadcn, en lugar de `<input type="time">`: el nativo sigue el idioma del
 * sistema operativo y se ve en inglés («--:-- AM»). v-model: "HH:mm".
 */
const props = withDefaults(
    defineProps<{
        modelValue: string | null | undefined;
        id?: string;
        disabled?: boolean;
        placeholder?: string;
        /** Primera y última hora de la rejilla (24 h). */
        desde?: number;
        hasta?: number;
    }>(),
    { placeholder: 'Hora', desde: 6, hasta: 22 },
);

const emit = defineEmits<{ 'update:modelValue': [valor: string] }>();

const hora = computed(() => (props.modelValue ?? '').slice(0, 5));

const horarios = computed(() => {
    const lista: { value: string; label: string }[] = [];

    for (let h = props.desde; h <= props.hasta; h++) {
        for (const m of [0, 15, 30, 45]) {
            const valor = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
            const sufijo = h < 12 ? 'a. m.' : 'p. m.';
            const h12 = h % 12 === 0 ? 12 : h % 12;
            lista.push({ value: valor, label: `${h12}:${String(m).padStart(2, '0')} ${sufijo}` });
        }
    }

    // Una hora capturada antes fuera de la rejilla se conserva.
    if (hora.value && !lista.some((o) => o.value === hora.value)) {
        lista.unshift({ value: hora.value, label: hora.value });
    }

    return lista;
});
</script>

<template>
    <SelectSimple
        :id="id"
        :model-value="hora"
        :opciones="horarios"
        :placeholder="placeholder"
        :aria-label="placeholder"
        :disabled="disabled"
        @update:model-value="(v) => emit('update:modelValue', String(v ?? ''))"
    />
</template>
