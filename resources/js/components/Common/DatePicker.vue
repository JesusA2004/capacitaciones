<script setup lang="ts">
import type { DateValue, CalendarDate } from '@internationalized/date';
import {
    DateFormatter,
    getLocalTimeZone,
    parseDate,
    today,
} from '@internationalized/date';
import { Calendar as CalendarIcon, X } from '@lucide/vue';
import { computed, ref, shallowRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/**
 * Selector de fecha con el mismo look de shadcn (Popover + Calendar) en vez
 * del calendario nativo del navegador (`<input type="date">`), que se ve
 * distinto -y feo- en cada navegador/SO. El v-model sigue siendo un string
 * ISO "YYYY-MM-DD" (o cadena vacía), igual que un input nativo, para no
 * tener que tocar cómo cada formulario ya guarda la fecha.
 *
 * Abre con animación, resalta el día de hoy, muestra mes y año con nombre
 * completo en español, se cierra al elegir y, con `atajos`, ofrece
 * «Ayer / Hoy / Mañana» (deshabilitados si quedan fuera de min/max). En una
 * fecha opcional, `limpiable` agrega «Quitar fecha» (el input nativo dejaba
 * borrarla; sin esto no habría forma visible de volver a dejarla vacía).
 */
export type AtajoFecha = 'ayer' | 'hoy' | 'manana';

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
         * calendario usa su rango por defecto (min/max o −100/+10 años).
         */
        anioDesde?: number;
        anioHasta?: number;
        /** Botones rápidos: `true` = Ayer, Hoy y Mañana; o la lista que se quiera. */
        atajos?: boolean | AtajoFecha[];
        /** Texto del botón con el día de la semana («martes, 10 de noviembre de 2026»). */
        diaSemana?: boolean;
        /** Fecha opcional: muestra «Quitar fecha» cuando ya hay una elegida. */
        limpiable?: boolean;
    }>(),
    {
        placeholder: 'Selecciona una fecha',
        atajos: false,
        diaSemana: false,
        limpiable: false,
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const zona = getLocalTimeZone();
const formateadorLargo = new DateFormatter('es-MX', { dateStyle: 'long' });
const formateadorCompleto = new DateFormatter('es-MX', { dateStyle: 'full' });
const formateadorMes = new DateFormatter('es-MX', { month: 'long' });

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

const abierto = ref(false);
const valorCalendario = computed(() => aFechaCalendario(props.modelValue));
const minCalendario = computed(() => aFechaCalendario(props.minValue));
const maxCalendario = computed(() => aFechaCalendario(props.maxValue));

// Mes que se ve en el calendario (abre en el de la fecha elegida, o en el de hoy).
const vista = shallowRef<DateValue>(valorCalendario.value ?? today(zona));

watch(abierto, (si) => {
    if (si) {
        vista.value = valorCalendario.value ?? today(zona);
    }
});

const meses = Array.from({ length: 12 }, (_, i) => {
    const nombre = formateadorMes.format(new Date(2026, i, 1));

    return {
        valor: String(i + 1),
        nombre: nombre.charAt(0).toUpperCase() + nombre.slice(1),
    };
});

// Años del Select. Siempre incluye el del valor y el de la vista aunque
// queden fuera del rango (p. ej. un nacimiento de 1935), para que el
// Select nunca aparezca vacío.
const anios = computed<number[]>(() => {
    const actual = today(zona).year;
    let desde = props.anioDesde ?? minCalendario.value?.year ?? actual - 100;
    let hasta = props.anioHasta ?? maxCalendario.value?.year ?? actual + 10;

    for (const anio of [valorCalendario.value?.year, vista.value.year]) {
        if (anio !== undefined) {
            desde = Math.min(desde, anio);
            hasta = Math.max(hasta, anio);
        }
    }

    return Array.from({ length: hasta - desde + 1 }, (_, i) => desde + i);
});

function cambiarVista(parte: { month?: number; year?: number }): void {
    vista.value = vista.value.set(parte);
}

const textoVisible = computed(() =>
    valorCalendario.value
        ? (props.diaSemana ? formateadorCompleto : formateadorLargo).format(
              valorCalendario.value.toDate(zona),
          )
        : props.placeholder,
);

const ETIQUETAS_ATAJO: Record<AtajoFecha, { texto: string; dias: number }> = {
    ayer: { texto: 'Ayer', dias: -1 },
    hoy: { texto: 'Hoy', dias: 0 },
    manana: { texto: 'Mañana', dias: 1 },
};

const listaAtajos = computed(() => {
    const claves: AtajoFecha[] =
        props.atajos === true
            ? ['ayer', 'hoy', 'manana']
            : Array.isArray(props.atajos)
              ? props.atajos
              : [];
    const hoy = today(zona);

    return claves.map((clave) => {
        const fecha = hoy.add({ days: ETIQUETAS_ATAJO[clave].dias });
        const fuera =
            (minCalendario.value !== undefined &&
                fecha.compare(minCalendario.value) < 0) ||
            (maxCalendario.value !== undefined &&
                fecha.compare(maxCalendario.value) > 0);

        return {
            clave,
            texto: ETIQUETAS_ATAJO[clave].texto,
            fecha,
            fuera,
            activo:
                valorCalendario.value !== undefined &&
                fecha.compare(valorCalendario.value) === 0,
        };
    });
});

function alSeleccionar(fecha: DateValue | undefined) {
    emit('update:modelValue', fecha ? fecha.toString() : '');

    if (fecha) {
        abierto.value = false;
    }
}

/**
 * Al abrir, el foco va al día elegido (o a hoy, o al primero disponible):
 * las flechas mueven por el calendario y Enter elige, sin tabular antes por
 * las flechas de mes y los Select.
 */
function enfocarDia(evento: Event) {
    const contenido = evento.target;

    if (!(contenido instanceof HTMLElement)) {
        return;
    }

    const base = '[data-slot="calendar-cell-trigger"]:not([data-outside-view])';
    const dia =
        contenido.querySelector<HTMLElement>(`${base}[data-selected]`) ??
        contenido.querySelector<HTMLElement>(
            `${base}[data-today]:not([data-disabled])`,
        ) ??
        contenido.querySelector<HTMLElement>(`${base}:not([data-disabled])`);

    if (dia) {
        evento.preventDefault();
        dia.focus();
    }
}

function quitarFecha() {
    emit('update:modelValue', '');
    abierto.value = false;
}
</script>

<template>
    <Popover v-model:open="abierto">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                :disabled="disabled"
                :class="
                    cn(
                        'w-full justify-start text-left font-normal transition-colors hover:border-[var(--mrl-navy)] hover:bg-[var(--mrl-menta)]',
                        !valorCalendario && 'text-muted-foreground',
                        abierto &&
                            'border-[var(--mrl-navy)] ring-2 ring-[var(--mrl-salvia)]',
                    )
                "
            >
                <CalendarIcon class="size-4 shrink-0 text-[var(--mrl-navy)]" />
                <span class="min-w-0 truncate first-letter:uppercase">{{
                    textoVisible
                }}</span>
            </Button>
        </PopoverTrigger>
        <PopoverContent
            class="w-auto overflow-hidden rounded-xl p-0 shadow-lg duration-200"
            align="start"
            @open-auto-focus="enfocarDia"
        >
            <Calendar
                v-model:placeholder="vista"
                :model-value="valorCalendario"
                :min-value="minCalendario"
                :max-value="maxCalendario"
                :class="
                    cn(
                        // Días redondos con hover suave; hoy, resaltado con anillo dorado.
                        '[&_[data-slot=calendar-cell-trigger]]:rounded-full [&_[data-slot=calendar-cell-trigger]]:transition-colors',
                        '[&_[data-slot=calendar-cell-trigger]:not([data-selected]):not([data-disabled]):hover]:bg-[var(--mrl-salvia)]',
                        '[&_[data-today]:not([data-selected])]:font-semibold [&_[data-today]:not([data-selected])]:ring-2 [&_[data-today]:not([data-selected])]:ring-[var(--mrl-dorado)] [&_[data-today]:not([data-selected])]:ring-inset',
                    )
                "
                @update:model-value="alSeleccionar"
            >
                <template #calendar-heading>
                    <div class="flex items-center justify-center gap-1">
                        <Select
                            :model-value="String(vista.month)"
                            @update:model-value="
                                (v) => cambiarVista({ month: Number(v) })
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="h-8 gap-1 px-2 text-sm font-medium"
                                aria-label="Mes"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent class="max-h-72">
                                <SelectItem
                                    v-for="mes in meses"
                                    :key="mes.valor"
                                    :value="mes.valor"
                                >
                                    {{ mes.nombre }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <Select
                            :model-value="String(vista.year)"
                            @update:model-value="
                                (v) => cambiarVista({ year: Number(v) })
                            "
                        >
                            <SelectTrigger
                                size="sm"
                                class="h-8 gap-1 px-2 text-sm font-medium"
                                aria-label="Año"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent class="max-h-72">
                                <SelectItem
                                    v-for="anio in anios"
                                    :key="anio"
                                    :value="String(anio)"
                                >
                                    {{ anio }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </template>
            </Calendar>
            <div
                v-if="listaAtajos.length || (limpiable && valorCalendario)"
                class="flex items-center gap-1.5 border-t border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] px-3 py-2"
            >
                <Button
                    v-for="atajo in listaAtajos"
                    :key="atajo.clave"
                    type="button"
                    size="sm"
                    :variant="atajo.activo ? 'default' : 'outline'"
                    class="h-7 flex-1 rounded-full text-xs"
                    :disabled="atajo.fuera"
                    :title="
                        atajo.fuera
                            ? 'Fuera de las fechas permitidas'
                            : formateadorLargo.format(atajo.fecha.toDate(zona))
                    "
                    @click="alSeleccionar(atajo.fecha)"
                >
                    {{ atajo.texto }}
                </Button>
                <Button
                    v-if="limpiable && valorCalendario"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="h-7 flex-1 rounded-full text-xs text-muted-foreground"
                    @click="quitarFecha"
                >
                    <X class="size-3.5" />
                    Quitar fecha
                </Button>
            </div>
        </PopoverContent>
    </Popover>
</template>
