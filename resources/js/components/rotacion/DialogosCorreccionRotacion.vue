<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ArrowRight, History, Loader2 } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { hoyIso } from '@/lib/bajas';
import { formatearFecha } from '@/lib/fechas';
import { getJson } from '@/lib/http';
import { bitacora } from '@/routes/reportes/rotacion/tablero';
import {
    causal as corregirCausalRuta,
    fecha as completarFechaRuta,
} from '@/routes/reportes/rotacion/tablero/correcciones';
import type {
    CorreccionBitacoraRotacion,
    FilaHistoricoRotacion,
    OpcionesRotacion,
} from '@/types';

export type ModoCorreccionRotacion = 'causal' | 'fecha' | 'historial';

/**
 * Correcciones auditadas de una baja del histórico (comentario obligatorio,
 * quedan en la bitácora) y la consulta de esa bitácora.
 */
const props = defineProps<{
    fila: FilaHistoricoRotacion | null;
    modo: ModoCorreccionRotacion | null;
    opciones: OpcionesRotacion;
}>();

const emit = defineEmits<{ cerrar: [] }>();

const abierto = computed({
    get: () => props.fila !== null && props.modo !== null,
    set: (v: boolean) => {
        if (!v) {
            emit('cerrar');
        }
    },
});

/* --- Corregir causal ----------------------------------------------------- */

const formCausal = useForm({
    fuente: '' as string,
    id: 0,
    causal_analitica_id: null as number | null,
    comentario: '',
});

const opcionesCausal = computed(() =>
    props.opciones.causales
        .filter((c) => c.activo || c.id === props.fila?.causal_analitica_id)
        .map((c) => ({
            value: c.id,
            label: c.nombre,
            descripcion:
                c.clasificacion === 'voluntaria'
                    ? 'Voluntaria'
                    : c.clasificacion === 'involuntaria'
                      ? 'Involuntaria'
                      : 'Sin clasificar',
        })),
);

/* --- Completar fecha ---------------------------------------------------- */

const formFecha = useForm({
    id: 0,
    fecha_ingreso: '',
    comentario: '',
});

/* --- Bitácora ----------------------------------------------------------- */

const cambios = ref<CorreccionBitacoraRotacion[] | null>(null);
const errorBitacora = ref<string | null>(null);

watch(
    () => [props.fila, props.modo] as const,
    async ([fila, modo]) => {
        if (!fila || !modo) {
            return;
        }

        if (modo === 'causal') {
            formCausal.clearErrors();
            formCausal.fuente = fila.fuente;
            formCausal.id = fila.id;
            formCausal.causal_analitica_id = null;
            formCausal.comentario = '';
        }

        if (modo === 'fecha') {
            formFecha.clearErrors();
            formFecha.id = fila.id;
            formFecha.fecha_ingreso = fila.fecha_ingreso ?? '';
            formFecha.comentario = '';
        }

        if (modo === 'historial') {
            cambios.value = null;
            errorBitacora.value = null;

            try {
                const r = await getJson<{
                    correcciones: CorreccionBitacoraRotacion[];
                }>(bitacora.url({ fuente: fila.fuente, id: fila.id }));
                cambios.value = r.correcciones;
            } catch {
                errorBitacora.value =
                    'No se pudo cargar el historial. Intenta de nuevo.';
            }
        }
    },
);

function guardarCausal(): void {
    formCausal.post(corregirCausalRuta.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('cerrar'),
    });
}

function guardarFecha(): void {
    formFecha.post(completarFechaRuta.url(), {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => emit('cerrar'),
    });
}

const CAMPOS: Record<string, string> = {
    causal_analitica_id: 'Causal homologada',
    fecha_ingreso: 'Fecha de ingreso',
    estado: 'Estado',
    baja_id: 'Baja ligada',
};
</script>

<template>
    <Dialog v-model:open="abierto">
        <DialogContent
            v-if="fila"
            class="max-h-[90svh] overflow-y-auto sm:max-w-lg"
        >
            <!-- Corregir causal -->
            <template v-if="modo === 'causal'">
                <DialogHeader>
                    <DialogTitle>Corregir causal homologada</DialogTitle>
                    <DialogDescription>
                        {{ fila.nombre }} · baja del
                        {{ formatearFecha(fila.fecha_baja) }}. Solo cambia la
                        estadística: el motivo original, la forma de salida y
                        los documentos no se tocan.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="guardarCausal"
                >
                    <dl
                        class="grid gap-2 rounded-2xl bg-crema/40 p-3 text-sm sm:grid-cols-2"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Motivo original
                            </dt>
                            <dd>{{ fila.motivo_original ?? '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                Causal actual
                            </dt>
                            <dd class="font-medium">
                                {{ fila.causal }}
                                <span class="text-xs text-muted-foreground"
                                    >· {{ fila.clasificacion_etiqueta }}</span
                                >
                            </dd>
                        </div>
                    </dl>
                    <div>
                        <Label class="mb-1.5">Nueva causal homologada</Label>
                        <SelectSimple
                            v-model="formCausal.causal_analitica_id"
                            :opciones="opcionesCausal"
                            numerico
                            placeholder="Elige la causal"
                            aria-label="Nueva causal homologada"
                        />
                        <InputError
                            :message="formCausal.errors.causal_analitica_id"
                        />
                    </div>
                    <div>
                        <Label for="comentario-causal" class="mb-1.5"
                            >Comentario (queda en la bitácora)</Label
                        >
                        <Textarea
                            id="comentario-causal"
                            v-model="formCausal.comentario"
                            rows="3"
                            maxlength="1000"
                            placeholder="¿Por qué se corrige? ¿De dónde sale el dato?"
                        />
                        <InputError :message="formCausal.errors.comentario" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="emit('cerrar')"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                formCausal.processing ||
                                formCausal.causal_analitica_id === null ||
                                formCausal.comentario.trim().length < 5
                            "
                        >
                            <Spinner
                                v-if="formCausal.processing"
                                class="size-4"
                            />
                            Guardar corrección
                        </Button>
                    </DialogFooter>
                </form>
            </template>

            <!-- Completar fecha de ingreso -->
            <template v-else-if="modo === 'fecha'">
                <DialogHeader>
                    <DialogTitle>Completar fecha de ingreso</DialogTitle>
                    <DialogDescription>
                        {{ fila.nombre }} · último día laborado
                        {{ formatearFecha(fila.fecha_baja) }}. Con la fecha se
                        calcula la antigüedad y entra a la rotación temprana.
                    </DialogDescription>
                </DialogHeader>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="guardarFecha"
                >
                    <div>
                        <Label class="mb-1.5">Fecha de ingreso</Label>
                        <DatePicker
                            v-model="formFecha.fecha_ingreso"
                            :max-value="fila.fecha_baja || hoyIso()"
                        />
                        <InputError :message="formFecha.errors.fecha_ingreso" />
                    </div>
                    <div>
                        <Label for="comentario-fecha" class="mb-1.5"
                            >¿De dónde sale el dato?</Label
                        >
                        <Textarea
                            id="comentario-fecha"
                            v-model="formFecha.comentario"
                            rows="3"
                            maxlength="1000"
                            placeholder="Por ejemplo: contrato firmado en el expediente físico."
                        />
                        <InputError :message="formFecha.errors.comentario" />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="emit('cerrar')"
                            >Cancelar</Button
                        >
                        <Button
                            type="submit"
                            :disabled="
                                formFecha.processing ||
                                !formFecha.fecha_ingreso ||
                                formFecha.comentario.trim().length < 5
                            "
                        >
                            <Spinner
                                v-if="formFecha.processing"
                                class="size-4"
                            />
                            Guardar fecha
                        </Button>
                    </DialogFooter>
                </form>
            </template>

            <!-- Historial de cambios -->
            <template v-else-if="modo === 'historial'">
                <DialogHeader>
                    <DialogTitle class="flex items-center gap-2">
                        <History class="size-4 text-bronce" />
                        Historial de cambios
                    </DialogTitle>
                    <DialogDescription>{{ fila.nombre }}</DialogDescription>
                </DialogHeader>
                <p
                    v-if="errorBitacora"
                    class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive"
                >
                    {{ errorBitacora }}
                </p>
                <p
                    v-else-if="cambios === null"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <Loader2 class="size-4 animate-spin" /> Cargando…
                </p>
                <p
                    v-else-if="!cambios.length"
                    class="text-sm text-muted-foreground"
                >
                    Esta baja no tiene correcciones.
                </p>
                <ol v-else class="flex flex-col gap-3">
                    <li
                        v-for="(c, i) in cambios"
                        :key="i"
                        class="rounded-2xl border border-border/60 p-3 text-sm"
                    >
                        <p class="text-xs font-medium text-muted-foreground">
                            {{ CAMPOS[c.campo] ?? c.campo }}
                        </p>
                        <p class="mt-1 flex flex-wrap items-center gap-1.5">
                            <span
                                class="rounded-lg bg-danger-soft px-2 py-0.5 line-through decoration-destructive/40"
                                >{{ c.antes ?? 'vacío' }}</span
                            >
                            <ArrowRight
                                class="size-3.5 text-muted-foreground"
                                aria-label="cambió a"
                            />
                            <span
                                class="rounded-lg bg-success-soft px-2 py-0.5"
                                >{{ c.despues ?? 'vacío' }}</span
                            >
                        </p>
                        <p class="mt-2">{{ c.comentario }}</p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ c.usuario ?? 'Sistema' }} ·
                            {{
                                c.fecha
                                    ? `${formatearFecha(c.fecha.slice(0, 10))} ${c.fecha.slice(11)}`
                                    : '—'
                            }}
                        </p>
                    </li>
                </ol>
            </template>
        </DialogContent>
    </Dialog>
</template>
