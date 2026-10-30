<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Calculator, FileUp } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import {
    colaboradores,
    iniciales,
    index,
    store,
} from '@/routes/rh/incapacidades';
import type {
    ColaboradorBusqueda,
    InicialAbierta,
    OpcionIncapacidad,
    ReglaDiasNomina,
} from '@/types';

const props = defineProps<{
    tipos: OpcionIncapacidad[];
    subtipos: OpcionIncapacidad[];
    regla: ReglaDiasNomina;
    mimes: string[];
    maxKb: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Permanencia', href: '' },
            { title: 'Incapacidades', href: index.url() },
            { title: 'Registrar', href: '' },
        ],
    },
});

const persona = ref<ColaboradorBusqueda | null>(null);
const inicialesAbiertas = ref<InicialAbierta[]>([]);

const form = useForm({
    colaborador_id: null as number | null,
    tipo: 'enfermedad_general',
    subtipo_riesgo: '',
    secuencia: 'inicial',
    incapacidad_inicial_id: '',
    serie_folio_imss: '',
    fecha_inicio: '',
    dias: '',
    fecha_evento: '',
    descripcion_evento: '',
    observaciones: '',
    certificado: null as File | null,
    st7: null as File | null,
    fecha_st7: '',
});

const esRiesgo = computed(() => form.tipo === 'riesgo_trabajo');
const esSubsecuente = computed(() => form.secuencia === 'subsecuente');
const inicialesDelTipo = computed(() =>
    inicialesAbiertas.value.filter((i) => i.tipo === form.tipo),
);
// El servicio reporta aquí una falla del NAS.
const errorArchivo = computed(
    () => (form.errors as Record<string, string>).archivo,
);
const aceptar = computed(() => props.mimes.map((m) => `.${m}`).join(','));

watch(persona, async (actual) => {
    form.colaborador_id = actual?.id ?? null;
    form.incapacidad_inicial_id = '';
    inicialesAbiertas.value = [];

    if (!actual) {
        return;
    }

    const r = await fetch(iniciales.url(actual.id), {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (r.ok) {
        inicialesAbiertas.value = (
            (await r.json()) as { data: InicialAbierta[] }
        ).data;
    }
});

watch(
    () => form.tipo,
    () => {
        form.incapacidad_inicial_id = '';

        if (!esRiesgo.value) {
            form.subtipo_riesgo = '';
            form.st7 = null;
        }
    },
);

/** Vista previa; el valor que cuenta lo calcula el servidor. */
const terminoPrevio = computed(() => {
    const dias = Number(form.dias);

    if (!form.fecha_inicio || !Number.isInteger(dias) || dias < 1) {
        return null;
    }

    const [a, m, d] = form.fecha_inicio.split('-').map(Number);
    const fin = new Date(a, m - 1, d + dias - 1);

    return `${fin.getFullYear()}-${String(fin.getMonth() + 1).padStart(2, '0')}-${String(fin.getDate()).padStart(2, '0')}`;
});

function archivoDe(evento: Event): File | null {
    return (evento.target as HTMLInputElement).files?.[0] ?? null;
}

function enviar() {
    if (form.processing) {
        return;
    }

    form.post(store.url(), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <Head title="Registrar incapacidad" />

    <form
        class="pagina-ancha grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_340px]"
        @submit.prevent="enviar"
    >
        <div
            class="flex flex-col gap-4 rounded-xl border border-border/60 bg-card p-5 shadow-sm"
        >
            <div>
                <h1 class="text-lg font-semibold">Registrar incapacidad</h1>
                <p class="text-sm text-muted-foreground">
                    Captura de Recursos Humanos
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label>Colaborador</Label>
                <BuscadorColaborador
                    v-model="persona"
                    :buscar-url="
                        (texto: string) =>
                            colaboradores.url({ query: { q: texto } })
                    "
                />
                <p
                    v-if="form.errors.colaborador_id"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ form.errors.colaborador_id }}
                </p>
            </div>

            <fieldset class="grid gap-1.5">
                <legend class="mb-1 text-sm font-medium">
                    Tipo de incapacidad
                </legend>
                <div class="grid gap-1 sm:grid-cols-3">
                    <button
                        v-for="tipo in tipos"
                        :key="tipo.value"
                        type="button"
                        :aria-pressed="form.tipo === tipo.value"
                        class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors"
                        :class="
                            form.tipo === tipo.value
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border hover:bg-accent/40'
                        "
                        @click="form.tipo = tipo.value"
                    >
                        {{ tipo.label }}
                    </button>
                </div>
            </fieldset>

            <fieldset v-if="esRiesgo" class="grid gap-1.5">
                <legend class="mb-1 text-sm font-medium">
                    Tipo de riesgo de trabajo
                </legend>
                <div class="grid gap-1 sm:grid-cols-3">
                    <button
                        v-for="subtipo in subtipos"
                        :key="subtipo.value"
                        type="button"
                        :disabled="esSubsecuente"
                        :aria-pressed="form.subtipo_riesgo === subtipo.value"
                        class="rounded-lg border px-3 py-2 text-sm font-medium transition-colors disabled:opacity-50"
                        :class="
                            form.subtipo_riesgo === subtipo.value
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'border-border hover:bg-accent/40'
                        "
                        @click="form.subtipo_riesgo = subtipo.value"
                    >
                        {{ subtipo.label }}
                    </button>
                </div>
                <p class="text-xs text-muted-foreground">
                    El accidente en trayecto se registra, pero se identifica
                    aparte de la siniestralidad.
                    {{
                        esSubsecuente
                            ? 'La subsecuente hereda el tipo de su inicial.'
                            : ''
                    }}
                </p>
                <p
                    v-if="form.errors.subtipo_riesgo"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ form.errors.subtipo_riesgo }}
                </p>
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-[220px_minmax(0,1fr)]">
                <fieldset class="grid gap-1.5">
                    <legend class="mb-1 text-sm font-medium">
                        La incapacidad es
                    </legend>
                    <div class="grid grid-cols-2 gap-1">
                        <button
                            v-for="opcion in [
                                { value: 'inicial', label: 'Inicial' },
                                { value: 'subsecuente', label: 'Subsecuente' },
                            ]"
                            :key="opcion.value"
                            type="button"
                            :aria-pressed="form.secuencia === opcion.value"
                            class="rounded-lg border px-2 py-2 text-sm font-medium transition-colors"
                            :class="
                                form.secuencia === opcion.value
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'border-border hover:bg-accent/40'
                            "
                            @click="form.secuencia = opcion.value"
                        >
                            {{ opcion.label }}
                        </button>
                    </div>
                </fieldset>
                <div v-if="esSubsecuente" class="grid gap-1.5">
                    <Label>Se liga a la incapacidad inicial</Label>
                    <SelectSimple
                        :model-value="form.incapacidad_inicial_id"
                        :opciones="
                            inicialesDelTipo.map((i) => ({
                                value: String(i.id),
                                label: i.etiqueta,
                            }))
                        "
                        placeholder="Elige la inicial"
                        @update:model-value="
                            (v) =>
                                (form.incapacidad_inicial_id = String(v ?? ''))
                        "
                    />
                    <p
                        v-if="persona && !inicialesDelTipo.length"
                        class="text-xs text-muted-foreground"
                    >
                        Esta persona no tiene iniciales abiertas de este tipo.
                    </p>
                    <p
                        v-if="form.errors.incapacidad_inicial_id"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.incapacidad_inicial_id }}
                    </p>
                </div>
            </div>

            <div class="grid gap-3 sm:grid-cols-4">
                <div class="grid gap-1.5">
                    <Label for="folio"
                        >Serie y folio del certificado IMSS</Label
                    >
                    <Input id="folio" v-model="form.serie_folio_imss" />
                    <p
                        v-if="form.errors.serie_folio_imss"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.serie_folio_imss }}
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="inicio">Fecha de inicio</Label>
                    <DatePicker id="inicio" v-model="form.fecha_inicio" />
                    <p
                        v-if="form.errors.fecha_inicio"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.fecha_inicio }}
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="dias">Días</Label>
                    <Input
                        id="dias"
                        v-model="form.dias"
                        type="number"
                        min="1"
                    />
                    <p
                        v-if="form.errors.dias"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ form.errors.dias }}
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label>Fecha de término</Label>
                    <p
                        class="flex min-h-9 items-center rounded-md border border-dashed border-border bg-salvia/40 px-3 text-sm font-semibold"
                    >
                        {{
                            terminoPrevio ? formatearFecha(terminoPrevio) : '—'
                        }}
                    </p>
                </div>
            </div>

            <p
                class="flex items-center gap-1.5 rounded-lg bg-salvia/50 px-3 py-2 text-xs"
            >
                <Calculator class="size-3.5 shrink-0" />
                <span
                    >Cálculo automático en días naturales:
                    <strong>inicio + días − 1</strong>. El día de inicio cuenta
                    como el primer día de incapacidad.</span
                >
            </p>

            <div
                v-if="esRiesgo && !esSubsecuente"
                class="grid gap-3 sm:grid-cols-[180px_minmax(0,1fr)]"
            >
                <div class="grid gap-1.5">
                    <Label for="evento">Fecha del evento</Label>
                    <DatePicker id="evento" v-model="form.fecha_evento" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="descripcion">Qué ocurrió (opcional)</Label>
                    <Textarea
                        id="descripcion"
                        v-model="form.descripcion_evento"
                        rows="2"
                    />
                </div>
            </div>

            <div class="grid gap-2">
                <h2 class="text-sm font-medium">Documentos</h2>
                <label
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-border/60 p-3"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold"
                            >Certificado de incapacidad ({{
                                esSubsecuente ? 'subsecuente' : 'inicial'
                            }})</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >Obligatorio. PDF o foto del certificado del
                            IMSS.</span
                        >
                    </span>
                    <span class="flex items-center gap-2 text-sm">
                        <FileUp class="size-4" />
                        <input
                            type="file"
                            :accept="aceptar"
                            class="max-w-52 text-xs"
                            @change="form.certificado = archivoDe($event)"
                        />
                    </span>
                </label>
                <p
                    v-if="form.errors.certificado"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ form.errors.certificado }}
                </p>

                <label
                    v-if="esRiesgo && !esSubsecuente"
                    class="flex flex-wrap items-center gap-3 rounded-lg border border-border/60 p-3"
                >
                    <span class="min-w-0 flex-1">
                        <span class="block text-sm font-semibold"
                            >ST-7 · Aviso para calificar probable riesgo de
                            trabajo</span
                        >
                        <span class="block text-xs text-muted-foreground"
                            >Se carga una vez por riesgo; si aún no lo tienes,
                            súbelo después desde el expediente.</span
                        >
                    </span>
                    <span class="flex items-center gap-2 text-sm">
                        <FileUp class="size-4" />
                        <input
                            type="file"
                            :accept="aceptar"
                            class="max-w-52 text-xs"
                            @change="form.st7 = archivoDe($event)"
                        />
                    </span>
                </label>
                <p
                    v-if="form.errors.st7"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ form.errors.st7 }}
                </p>
                <p v-if="esRiesgo" class="text-xs text-muted-foreground">
                    ST-2 (dictamen de alta) y ST-3 (opcional, incapacidad
                    permanente) se suben al expediente del riesgo cuando
                    existan.
                </p>
                <p
                    v-if="errorArchivo"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ errorArchivo }}
                </p>
            </div>

            <div class="grid gap-1.5">
                <Label for="observaciones">Observaciones (opcional)</Label>
                <Textarea
                    id="observaciones"
                    v-model="form.observaciones"
                    rows="2"
                />
            </div>

            <div class="flex justify-end gap-2">
                <Button as-child variant="secondary">
                    <Link :href="index.url()">Cancelar</Link>
                </Button>
                <Button type="submit" :disabled="form.processing">
                    <Spinner v-if="form.processing" />
                    Guardar incapacidad
                </Button>
            </div>
        </div>

        <aside class="flex flex-col gap-3">
            <div
                class="rounded-xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <h2 class="font-semibold">Estatus</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Enfermedad general y maternidad concluyen al llegar la fecha
                    de término; el riesgo de trabajo concluye al cargar el ST-2.
                </p>
            </div>
            <div
                class="rounded-xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <h2 class="font-semibold">Impacto en nómina</h2>
                <p class="mt-1 text-xs text-muted-foreground">
                    Los días que caen en cada periodo se reflejan en el recibo
                    en borrador. Un recibo ya emitido no cambia solo: queda una
                    rectificación para que la revises.
                </p>
                <p
                    v-if="regla.aviso"
                    class="mt-2 flex gap-1.5 rounded-lg bg-warning-soft p-2.5 text-xs text-warning"
                >
                    <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                    <span>{{ regla.aviso }}</span>
                </p>
            </div>
        </aside>
    </form>
</template>
