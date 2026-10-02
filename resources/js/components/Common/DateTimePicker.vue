<script setup lang="ts">
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';

/**
 * Fecha + hora con componentes shadcn (DatePicker + Select de horarios cada
 * 15 min) en lugar de `<input type="datetime-local">`. v-model:
 * "YYYY-MM-DDTHH:mm" (mismo formato que el input nativo).
 */
const props = defineProps<{
    modelValue: string | null | undefined;
    id?: string;
    disabled?: boolean;
    minValue?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [valor: string] }>();

const fecha = computed(() => (props.modelValue ?? '').slice(0, 10));
const hora = computed(() => (props.modelValue ?? '').slice(11, 16));

const horarios = computed(() => {
    const lista: { value: string; label: string }[] = [];

    for (let h = 6; h <= 22; h++) {
        for (const m of [0, 15, 30, 45]) {
            const valor = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
            const sufijo = h < 12 ? 'a. m.' : 'p. m.';
            const h12 = h % 12 === 0 ? 12 : h % 12;
            lista.push({
                value: valor,
                label: `${h12}:${String(m).padStart(2, '0')} ${sufijo}`,
            });
        }
    }

    // Una hora capturada antes fuera de la rejilla se conserva.
    if (hora.value && !lista.some((o) => o.value === hora.value)) {
        lista.unshift({ value: hora.value, label: hora.value });
    }

    return lista;
});

function actualizar(nuevaFecha: string, nuevaHora: string) {
    emit(
        'update:modelValue',
        nuevaFecha ? `${nuevaFecha}T${nuevaHora || '09:00'}` : '',
    );
}
</script>

<template>
    <div class="grid grid-cols-[minmax(0,1fr)_8.5rem] gap-2">
        <DatePicker
            :id="id"
            :model-value="fecha"
            :disabled="disabled"
            :min-value="minValue"
            @update:model-value="(v) => actualizar(v, hora)"
        />
        <SelectSimple
            :model-value="hora"
            :opciones="horarios"
            placeholder="Hora"
            aria-label="Hora"
            :disabled="disabled || !fecha"
            @update:model-value="(v) => actualizar(fecha, v)"
        />
    </div>
</template>
