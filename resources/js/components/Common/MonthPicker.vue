<script setup lang="ts">
import { CalendarDays, ChevronLeft, ChevronRight } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';

/**
 * Selector de mes estilo shadcn (Popover + rejilla de 12 meses) en lugar de
 * `<input type="month">`, que en Chrome/Edge/Safari se ve distinto y feo.
 * v-model: "YYYY-MM".
 */
const props = withDefaults(
    defineProps<{
        modelValue: string;
        id?: string;
        disabled?: boolean;
        class?: string;
        ariaLabel?: string;
    }>(),
    { ariaLabel: 'Mes' },
);

const emit = defineEmits<{ 'update:modelValue': [valor: string] }>();

const MESES = [
    'Ene',
    'Feb',
    'Mar',
    'Abr',
    'May',
    'Jun',
    'Jul',
    'Ago',
    'Sep',
    'Oct',
    'Nov',
    'Dic',
];
const MESES_LARGOS = [
    'enero',
    'febrero',
    'marzo',
    'abril',
    'mayo',
    'junio',
    'julio',
    'agosto',
    'septiembre',
    'octubre',
    'noviembre',
    'diciembre',
];

const abierto = ref(false);
const anio = ref(
    Number(props.modelValue.slice(0, 4)) || new Date().getFullYear(),
);
const mesSeleccionado = computed(
    () => Number(props.modelValue.slice(5, 7)) || 0,
);
const anioSeleccionado = computed(
    () => Number(props.modelValue.slice(0, 4)) || 0,
);

watch(abierto, (si) => {
    if (si) {
        anio.value = anioSeleccionado.value || new Date().getFullYear();
    }
});

const texto = computed(() =>
    mesSeleccionado.value
        ? `${MESES_LARGOS[mesSeleccionado.value - 1].replace(/^./, (l) => l.toUpperCase())} de ${anioSeleccionado.value}`
        : 'Selecciona un mes',
);

function elegir(indice: number) {
    emit(
        'update:modelValue',
        `${anio.value}-${String(indice + 1).padStart(2, '0')}`,
    );
    abierto.value = false;
}

const hoy = new Date();
</script>

<template>
    <Popover v-model:open="abierto">
        <PopoverTrigger as-child>
            <Button
                :id="id"
                type="button"
                variant="outline"
                :disabled="disabled"
                :aria-label="ariaLabel"
                :class="cn('h-9 justify-start font-normal', props.class)"
            >
                <CalendarDays class="size-4 text-muted-foreground" />
                {{ texto }}
            </Button>
        </PopoverTrigger>
        <PopoverContent class="w-64 p-3" align="start">
            <div class="mb-2 flex items-center justify-between">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Año anterior"
                    @click="anio--"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <span class="text-sm font-medium tabular-nums">{{ anio }}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Año siguiente"
                    @click="anio++"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
            <div class="grid grid-cols-3 gap-1.5">
                <Button
                    v-for="(m, i) in MESES"
                    :key="m"
                    type="button"
                    size="sm"
                    :variant="
                        anio === anioSeleccionado && i + 1 === mesSeleccionado
                            ? 'default'
                            : 'ghost'
                    "
                    :class="
                        anio === hoy.getFullYear() &&
                        i === hoy.getMonth() &&
                        !(
                            anio === anioSeleccionado &&
                            i + 1 === mesSeleccionado
                        )
                            ? 'ring-1 ring-border'
                            : ''
                    "
                    @click="elegir(i)"
                    >{{ m }}</Button
                >
            </div>
        </PopoverContent>
    </Popover>
</template>
