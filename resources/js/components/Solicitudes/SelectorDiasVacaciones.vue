<script setup lang="ts">
import { ChevronLeft, ChevronRight, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';

/**
 * Calendario de selección múltiple para vacaciones: el colaborador elige
 * días sueltos (no un rango). Los días de `diasNoSeleccionables` (0 =
 * domingo, regla MR. LANA) y los ya pasados no se pueden elegir. El
 * backend vuelve a validar todo (FechasSolicitudService). v-model: lista
 * de fechas «AAAA-MM-DD» ordenadas.
 */
const props = withDefaults(
    defineProps<{
        modelValue: string[];
        diasNoSeleccionables?: number[];
        maximo?: number;
    }>(),
    { diasNoSeleccionables: () => [0], maximo: 60 },
);

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

const NOMBRES_DIA = ['L', 'M', 'M', 'J', 'V', 'S', 'D'];

function iso(fecha: Date): string {
    const m = String(fecha.getMonth() + 1).padStart(2, '0');
    const d = String(fecha.getDate()).padStart(2, '0');

    return `${fecha.getFullYear()}-${m}-${d}`;
}

const hoy = new Date();
hoy.setHours(0, 0, 0, 0);
const mes = ref(new Date(hoy.getFullYear(), hoy.getMonth(), 1));

const titulo = computed(() =>
    mes.value.toLocaleDateString('es-MX', { month: 'long', year: 'numeric' }),
);

const seleccion = computed(() => new Set(props.modelValue));

/** Celdas del mes empezando en lunes (null = relleno). */
const celdas = computed(() => {
    const primero = mes.value;
    const desfase = (primero.getDay() + 6) % 7;
    const total = new Date(
        primero.getFullYear(),
        primero.getMonth() + 1,
        0,
    ).getDate();
    const lista: (Date | null)[] = Array.from({ length: desfase }, () => null);

    for (let dia = 1; dia <= total; dia++) {
        lista.push(new Date(primero.getFullYear(), primero.getMonth(), dia));
    }

    return lista;
});

function deshabilitado(fecha: Date): boolean {
    return fecha < hoy || props.diasNoSeleccionables.includes(fecha.getDay());
}

function alternar(fecha: Date): void {
    if (deshabilitado(fecha)) {
        return;
    }

    const clave = iso(fecha);
    const nueva = new Set(seleccion.value);

    if (nueva.has(clave)) {
        nueva.delete(clave);
    } else if (nueva.size < props.maximo) {
        nueva.add(clave);
    }

    emit('update:modelValue', [...nueva].sort());
}

function quitar(clave: string): void {
    emit(
        'update:modelValue',
        props.modelValue.filter((d) => d !== clave),
    );
}

function moverMes(delta: number): void {
    mes.value = new Date(
        mes.value.getFullYear(),
        mes.value.getMonth() + delta,
        1,
    );
}

const puedeRetroceder = computed(
    () =>
        mes.value.getFullYear() > hoy.getFullYear() ||
        mes.value.getMonth() > hoy.getMonth(),
);

function etiqueta(clave: string): string {
    const [a, m, d] = clave.split('-').map(Number);

    return new Date(a, m - 1, d).toLocaleDateString('es-MX', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="rounded-xl border border-border/60 p-3">
            <div class="mb-2 flex items-center justify-between">
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    :disabled="!puedeRetroceder"
                    aria-label="Mes anterior"
                    @click="moverMes(-1)"
                >
                    <ChevronLeft class="size-4" />
                </Button>
                <p class="text-sm font-semibold capitalize">{{ titulo }}</p>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label="Mes siguiente"
                    @click="moverMes(1)"
                >
                    <ChevronRight class="size-4" />
                </Button>
            </div>
            <div
                class="grid grid-cols-7 gap-1 text-center text-xs text-muted-foreground"
            >
                <span v-for="(n, i) in NOMBRES_DIA" :key="i">{{ n }}</span>
            </div>
            <div class="mt-1 grid grid-cols-7 gap-1">
                <template v-for="(fecha, i) in celdas" :key="i">
                    <span v-if="!fecha" />
                    <button
                        v-else
                        type="button"
                        class="aspect-square rounded-lg text-sm tabular-nums transition-colors"
                        :class="
                            seleccion.has(iso(fecha))
                                ? 'bg-primary font-semibold text-primary-foreground hover:bg-primary/90'
                                : deshabilitado(fecha)
                                  ? 'cursor-not-allowed text-muted-foreground/40 line-through'
                                  : 'hover:bg-accent'
                        "
                        :disabled="deshabilitado(fecha)"
                        :aria-pressed="seleccion.has(iso(fecha))"
                        :title="
                            diasNoSeleccionables.includes(fecha.getDay())
                                ? 'El domingo no cuenta como vacaciones'
                                : undefined
                        "
                        @click="alternar(fecha)"
                    >
                        {{ fecha.getDate() }}
                    </button>
                </template>
            </div>
        </div>

        <p class="text-sm">
            Días seleccionados:
            <span class="font-semibold tabular-nums">{{
                modelValue.length
            }}</span>
        </p>
        <ul v-if="modelValue.length" class="flex flex-wrap gap-1.5">
            <li
                v-for="dia in modelValue"
                :key="dia"
                class="flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 text-xs text-primary"
            >
                {{ etiqueta(dia) }}
                <button
                    type="button"
                    class="rounded-full hover:bg-primary/20"
                    :aria-label="`Quitar ${etiqueta(dia)}`"
                    @click="quitar(dia)"
                >
                    <X class="size-3" />
                </button>
            </li>
        </ul>
    </div>
</template>
