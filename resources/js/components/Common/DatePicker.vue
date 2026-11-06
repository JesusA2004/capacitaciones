<script setup lang="ts">
import type { DateValue } from '@internationalized/date';
import {
    CalendarDate,
    DateFormatter,
    getLocalTimeZone,
    parseDate,
    today,
} from '@internationalized/date';
import { Calendar as CalendarIcon } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
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
        /**
         * Rango del Select de año (solo la lista; no limita los días). Útil
         * para fechas de nacimiento: `anio-desde="1940"`. Sin él, el
         * calendario usa su rango por defecto (min/max o ±100 años).
         */
        anioDesde?: number;
        anioHasta?: number;
    }>(),
    {
        placeholder: 'Selecciona una fecha',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const formateador = new DateFormatter('es-MX', { dateStyle: 'long' });

function aFechaCalendario(
    valor: string | null | undefined,
): CalendarDate | undefined {
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

// Lista explícita de años para el Select. Siempre incluye el año del valor
// actual aunque quede fuera del rango (p. ej. un nacimiento de 1935), para
// que el Select nunca aparezca vacío.
const rangoAnios = computed<DateValue[] | undefined>(() => {
    if (props.anioDesde === undefined && props.anioHasta === undefined) {
        return undefined;
    }

    const anioActual = today(getLocalTimeZone()).year;
    const anioValor = valorCalendario.value?.year;
    let desde = props.anioDesde ?? anioActual - 100;
    let hasta = props.anioHasta ?? anioActual + 10;

    if (anioValor !== undefined) {
        desde = Math.min(desde, anioValor);
        hasta = Math.max(hasta, anioValor);
    }

    return Array.from(
        { length: hasta - desde + 1 },
        (_, i) => new CalendarDate(desde + i, 1, 1),
    );
});

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
                :year-range="rangoAnios"
                layout="month-and-year"
                @update:model-value="alSeleccionar"
            />
        </PopoverContent>
    </Popover>
</template>
