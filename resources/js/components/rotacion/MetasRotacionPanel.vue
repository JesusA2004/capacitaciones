<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ChevronDown, Target } from '@lucide/vue';
import { computed, ref } from 'vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatearFechaHora } from '@/lib/fechas';
import { etiquetaMes } from '@/lib/rotacion';
import rutasBajas from '@/routes/rh/bajas';
import type { MetasRotacionPanel } from '@/types';

/**
 * Metas mensuales del semáforo de rotación. Registrar una meta agrega una
 * fila con vigencia: los meses anteriores conservan la que tenían.
 */
const props = defineProps<{
    metas: MetasRotacionPanel;
    puedeEditar: boolean;
    mesInicial: string;
}>();

const editando = ref(false);
const verHistorial = ref(false);

const form = useForm({
    segmento: props.metas.vigentes[0]?.segmento ?? 'global',
    verde_hasta: '',
    ambar_hasta: '',
    vigente_desde: props.mesInicial,
    motivo: '',
});

const segmentos = computed(() =>
    props.metas.vigentes.map((m) => ({ value: m.segmento, label: m.etiqueta })),
);

function guardar(): void {
    form.post(rutasBajas.rotacionMetas.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('verde_hasta', 'ambar_hasta', 'motivo');
            editando.value = false;
        },
    });
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
        aria-labelledby="titulo-metas"
    >
        <div class="flex items-center justify-between gap-2">
            <h2
                id="titulo-metas"
                class="flex items-center gap-2 text-sm font-semibold"
            >
                <span
                    class="grid size-7 place-items-center rounded-lg bg-salvia text-primary"
                    aria-hidden="true"
                    ><Target class="size-4"
                /></span>
                Metas mensuales del semáforo
            </h2>
            <Button
                v-if="puedeEditar"
                variant="outline"
                size="sm"
                :aria-expanded="editando"
                @click="editando = !editando"
                >{{ editando ? 'Cancelar' : 'Registrar meta' }}</Button
            >
        </div>

        <ul class="grid gap-2 sm:grid-cols-3">
            <li
                v-for="m in metas.vigentes"
                :key="m.segmento"
                class="rounded-2xl bg-crema/40 p-3 text-xs"
            >
                <p class="font-semibold text-foreground">{{ m.etiqueta }}</p>
                <template
                    v-if="m.verde_hasta !== null && m.ambar_hasta !== null"
                >
                    <p class="mt-1.5 flex items-center gap-1.5 tabular-nums">
                        <span
                            class="size-2 rounded-full bg-semaforo-baja"
                            aria-hidden="true"
                        />Verde ≤ {{ m.verde_hasta }} %
                    </p>
                    <p class="flex items-center gap-1.5 tabular-nums">
                        <span
                            class="size-2 rounded-full bg-semaforo-ambar"
                            aria-hidden="true"
                        />Ámbar ≤ {{ m.ambar_hasta }} %
                    </p>
                    <p class="flex items-center gap-1.5 tabular-nums">
                        <span
                            class="size-2 rounded-full bg-semaforo-sube"
                            aria-hidden="true"
                        />Rojo &gt; {{ m.ambar_hasta }} %
                    </p>
                </template>
                <p v-else class="mt-1.5 text-muted-foreground">
                    Sin meta registrada.
                </p>
            </li>
        </ul>

        <form
            v-if="editando"
            class="grid gap-3 rounded-2xl border border-border/60 p-3 sm:grid-cols-2"
            @submit.prevent="guardar"
        >
            <div class="min-w-0">
                <Label class="mb-1 text-xs">Segmento</Label>
                <SelectSimple
                    v-model="form.segmento"
                    :opciones="segmentos"
                    aria-label="Segmento"
                />
            </div>
            <div class="min-w-0">
                <Label class="mb-1 text-xs">Aplica desde</Label>
                <MonthPicker
                    v-model="form.vigente_desde"
                    aria-label="Aplica desde"
                />
                <InputError :message="form.errors.vigente_desde" />
            </div>
            <div class="min-w-0">
                <Label for="meta-verde" class="mb-1 text-xs"
                    >Verde hasta (%)</Label
                >
                <Input
                    id="meta-verde"
                    v-model="form.verde_hasta"
                    type="number"
                    inputmode="decimal"
                    step="0.01"
                    min="0"
                />
                <InputError :message="form.errors.verde_hasta" />
            </div>
            <div class="min-w-0">
                <Label for="meta-ambar" class="mb-1 text-xs"
                    >Ámbar hasta (%)</Label
                >
                <Input
                    id="meta-ambar"
                    v-model="form.ambar_hasta"
                    type="number"
                    inputmode="decimal"
                    step="0.01"
                    min="0"
                />
                <InputError :message="form.errors.ambar_hasta" />
            </div>
            <div class="min-w-0 sm:col-span-2">
                <Label for="meta-motivo" class="mb-1 text-xs"
                    >Motivo del cambio</Label
                >
                <Textarea id="meta-motivo" v-model="form.motivo" rows="2" />
                <InputError :message="form.errors.motivo" />
            </div>
            <Button
                type="submit"
                class="sm:col-span-2"
                :disabled="form.processing"
                >Registrar meta</Button
            >
        </form>

        <button
            type="button"
            class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
            :aria-expanded="verHistorial"
            @click="verHistorial = !verHistorial"
        >
            Historial de metas
            <ChevronDown
                class="size-3.5 transition-transform"
                :class="verHistorial && 'rotate-180'"
            />
        </button>
        <ul v-if="verHistorial" class="flex flex-col gap-2 text-xs">
            <li v-if="!metas.historial.length" class="text-muted-foreground">
                Aún no hay metas registradas.
            </li>
            <li
                v-for="h in metas.historial"
                :key="h.id"
                class="border-t border-border/50 pt-2"
            >
                <strong>{{ h.segmento }}</strong>
                · verde ≤ {{ h.verde_hasta }} % · ámbar ≤ {{ h.ambar_hasta }} %
                · desde
                {{ etiquetaMes(h.vigente_desde) }}
                <p class="text-muted-foreground">
                    {{ h.motivo }}
                    <template v-if="h.registrado_por">
                        · {{ h.registrado_por }}</template
                    >
                    <template v-if="h.registrado_en">
                        · {{ formatearFechaHora(h.registrado_en) }}</template
                    >
                </p>
            </li>
        </ul>
    </section>
</template>
