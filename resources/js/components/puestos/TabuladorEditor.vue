<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Info, Loader2 } from '@lucide/vue';
import { computed } from 'vue';
import CampoMoneda from '@/components/Common/CampoMoneda.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import BarraTabulador from '@/components/puestos/BarraTabulador.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatoMoneda } from '@/lib/utils';
import { guardar } from '@/routes/salarios/tabulador';
import type { RangoTabuladorVigente } from '@/types';

/**
 * Captura del tabulador de un puesto: mínimo, medio y máximo mensual con su
 * fuente y fecha. Guardar crea una versión nueva (la anterior queda en el
 * historial); ningún sueldo real cambia. La vista previa de la barra se
 * actualiza mientras se escribe.
 */
const props = defineProps<{
    puestoId: number;
    rango: RangoTabuladorVigente | null;
}>();

const emit = defineEmits<{ guardado: []; cancelar: [] }>();

const hoy = new Date().toLocaleDateString('en-CA');

const form = useForm({
    minimo: props.rango?.minimo ?? (null as number | null),
    medio: props.rango?.medio ?? (null as number | null),
    maximo: props.rango?.maximo ?? (null as number | null),
    fuente: props.rango?.fuente ?? '',
    fecha_actualizacion: props.rango?.fecha_actualizacion ?? hoy,
    motivo: '',
});

/** El medio se sugiere al centro del rango si aún no se captura. */
function sugerirMedio(): void {
    if (form.medio === null && form.minimo !== null && form.maximo !== null) {
        form.medio = Math.round(((form.minimo + form.maximo) / 2) * 100) / 100;
    }
}

const avisoOrden = computed(() => {
    const { minimo, medio, maximo } = form;

    if (minimo !== null && medio !== null && medio < minimo) {
        return 'El medio no puede ser menor que el mínimo.';
    }

    if (medio !== null && maximo !== null && maximo < medio) {
        return 'El máximo no puede ser menor que el medio.';
    }

    return null;
});

const vistaPrevia = computed(() =>
    form.minimo !== null &&
    form.medio !== null &&
    form.maximo !== null &&
    avisoOrden.value === null
        ? { minimo: form.minimo, medio: form.medio, maximo: form.maximo }
        : null,
);

const amplitud = computed(() =>
    vistaPrevia.value && vistaPrevia.value.minimo > 0
        ? Math.round(
              ((vistaPrevia.value.maximo - vistaPrevia.value.minimo) /
                  vistaPrevia.value.minimo) *
                  100,
          )
        : null,
);

const listo = computed(
    () =>
        vistaPrevia.value !== null &&
        form.fuente.trim() !== '' &&
        form.fecha_actualizacion !== '',
);

function enviar(): void {
    form.put(guardar.url(props.puestoId), {
        preserveScroll: true,
        onSuccess: () => emit('guardado'),
    });
}
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="enviar">
        <div class="grid gap-3 sm:grid-cols-3">
            <div
                v-for="campo in [
                    {
                        clave: 'minimo',
                        etiqueta: 'Mínimo',
                        ayuda: 'Piso del puesto',
                    },
                    {
                        clave: 'medio',
                        etiqueta: 'Medio',
                        ayuda: 'Referencia de mercado',
                    },
                    {
                        clave: 'maximo',
                        etiqueta: 'Máximo',
                        ayuda: 'Tope del puesto',
                    },
                ] as const"
                :key="campo.clave"
                class="grid gap-1.5 rounded-2xl border bg-crema/30 p-3"
            >
                <Label
                    :for="`tab-${campo.clave}`"
                    class="flex items-baseline justify-between"
                >
                    <span class="font-semibold">{{ campo.etiqueta }}</span>
                    <span
                        class="text-[11px] font-normal text-muted-foreground"
                        >{{ campo.ayuda }}</span
                    >
                </Label>
                <CampoMoneda
                    :id="`tab-${campo.clave}`"
                    v-model="form[campo.clave]"
                    :invalido="!!form.errors[campo.clave]"
                    @blur="campo.clave !== 'medio' && sugerirMedio()"
                />
                <InputError :message="form.errors[campo.clave]" />
            </div>
        </div>

        <p
            v-if="avisoOrden"
            class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-2 text-xs"
        >
            <Info class="size-3.5 shrink-0" /> {{ avisoOrden }}
        </p>

        <div
            v-if="vistaPrevia"
            class="rounded-2xl border border-dashed bg-background/60 px-2 pt-1"
        >
            <p class="px-2 pt-2 text-xs text-muted-foreground">
                Vista previa · amplitud del rango
                <strong class="text-foreground tabular-nums"
                    >{{ amplitud }}%</strong
                >
                ({{ formatoMoneda(vistaPrevia.maximo - vistaPrevia.minimo) }})
            </p>
            <BarraTabulador :rango="vistaPrevia" />
        </div>

        <div class="grid gap-3 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div class="grid gap-1.5">
                <Label for="tab-fuente">Fuente del estudio</Label>
                <Input
                    id="tab-fuente"
                    v-model="form.fuente"
                    class="h-11 rounded-xl"
                    placeholder="Ej. Estudio de mercado 2026, OCC, Dirección General"
                />
                <InputError :message="form.errors.fuente" />
            </div>
            <div class="grid gap-1.5">
                <Label for="tab-fecha">Fecha de actualización</Label>
                <DatePicker
                    id="tab-fecha"
                    v-model="form.fecha_actualizacion"
                    :max-value="hoy"
                />
                <InputError :message="form.errors.fecha_actualizacion" />
            </div>
        </div>

        <div class="grid gap-1.5">
            <Label for="tab-motivo">
                Motivo del cambio
                <span class="font-normal text-muted-foreground"
                    >(opcional)</span
                >
            </Label>
            <Input
                id="tab-motivo"
                v-model="form.motivo"
                class="h-11 rounded-xl"
                placeholder="Ej. Ajuste anual por inflación"
            />
            <InputError :message="form.errors.motivo" />
        </div>

        <div
            class="flex flex-col-reverse gap-2 border-t pt-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-xs text-muted-foreground">
                Se guarda como versión nueva. Ningún sueldo real cambia.
            </p>
            <div class="flex gap-2">
                <Button type="button" variant="ghost" @click="emit('cancelar')">
                    Cancelar
                </Button>
                <Button type="submit" :disabled="!listo || form.processing">
                    <Loader2
                        v-if="form.processing"
                        class="size-4 animate-spin"
                    />
                    <Check v-else class="size-4" />
                    Guardar tabulador
                </Button>
            </div>
        </div>
    </form>
</template>
