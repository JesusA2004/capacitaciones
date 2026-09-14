<script setup lang="ts">
import type { CalendarDate, DateValue } from '@internationalized/date';
import {
    DateFormatter,
    getLocalTimeZone,
    parseDate,
} from '@internationalized/date';
import { Calendar as CalendarIcon } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Selector de fecha con el mismo look de shadcn (Popover + Calendar) en vez
 * del calendario nativo del navegador (`<input type="date">`), que se ve
 * distinto -y feo- en cada navegador/SO. El v-model sigue siendo un string
 * ISO "YYYY-MM-DD" (o cadena vacía), igual que un input nativo, para no
 * tener que tocar cómo cada formulario ya guarda la fecha.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string | null | undefined;
        id?: string;
        disabled?: boolean;
        placeholder?: string;
        minValue?: string;
        maxValue?: string;
    }>(),
    {
        placeholder: 'Selecciona una fecha',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const formateador = new DateFormatter('es-MX', { dateStyle: 'long' });

function aFechaCalendario(valor: string | null | undefined): CalendarDate | undefined {
    if (!valor) {
        return undefined;
    }

    try {
        return parseDate(valor);
    } catch {
        return undefined;
    }
}

const valorCalendario = computed(() => aFechaCalendario(props.modelValue));
const minCalendario = computed(() => aFechaCalendario(props.minValue));
const maxCalendario = computed(() => aFechaCalendario(props.maxValue));

const textoVisible = computed(() =>
    valorCalendario.value
        ? formateador.format(valorCalendario.value.toDate(getLocalTimeZone()))
        : props.placeholder,
);

function alSeleccionar(fecha: DateValue | undefined) {
    emit('update:modelValue', fecha ? fecha.toString() : '');
}
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                :disabled="disabled"
                :class="
                    cn(
                        'w-full justify-start text-left font-normal',
                        !valorCalendario && 'text-muted-foreground',
                    )
                "
            >
                <CalendarIcon class="size-4" />
                {{ textoVisible }}
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-auto p-0" align="start">
            <Calendar
                :model-value="valorCalendario"
                :min-value="minCalendario"
                :max-value="maxCalendario"
                layout="month-and-year"
                @update:model-value="alSeleccionar"
            />
        </PopoverContent>
    </Popover>
</template>
