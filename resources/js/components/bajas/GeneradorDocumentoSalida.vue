<script setup lang="ts">
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CheckCircle2,
    Download,
    Eye,
    FileUp,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EditorDocumentoSalida from '@/components/bajas/EditorDocumentoSalida.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import rutasSalida from '@/routes/rh/bajas/salida';
import type { PanelDocumentoSalida } from '@/types';

/**
 * «Generar documento de salida»: según la forma, carta de renuncia, convenio,
 * aviso de rescisión o constancia. Vista previa real, PDF para firma, carga del
 * firmado y, en rescisión, hechos obligatorios y entrega. Cada acción la valida
 * BajaDocumentoSalidaService.
 */
const props = defineProps<{ panel: PanelDocumentoSalida }>();

const page = usePage();
const errores = computed(() =>
    Object.values((page.props.errors ?? {}) as Record<string, string>),
);
const procesando = ref(false);
const motivo = ref('');
const pidiendoVersion = ref(false);
const huellaRegistrada = ref(false);
const corrigiendo = ref(false);

const opciones = [
    {
        clave: 'carta_renuncia',
        titulo: 'Carta de renuncia',
        detalle: 'Renuncia voluntaria',
    },
    {
        clave: 'convenio_terminacion',
        titulo: 'Convenio de terminación',
        detalle: 'No renovación',
    },
    {
        clave: 'aviso_rescision',
        titulo: 'Aviso de rescisión',
        detalle: 'Rescisión',
    },
    {
        clave: 'constancia_terminacion',
        titulo: 'Constancia',
        detalle: 'Otras terminaciones',
    },
];

const esRescision = computed(() => props.panel.forma === 'RE');

function formularioInicial() {
    const a = props.panel.aviso;

    return {
        fundamento: a?.fundamento ?? props.panel.fundamento_catalogo ?? '',
        que_ocurrio: a?.que_ocurrio ?? '',
        hechos_en: a?.hechos_en ?? '',
        lugar: a?.lugar ?? '',
        constataron: a?.constataron ?? '',
        relato: a?.relato ?? '',
        testigos: a?.testigos?.length
            ? a.testigos.map((t) => ({ ...t }))
            : Array.from({ length: props.panel.testigos_minimos }, () => ({
                  nombre: '',
                  cargo: '',
              })),
        fecha_aviso: a?.fecha_aviso ?? '',
        metodo_entrega: a?.metodo_entrega ?? 'personal',
        fecha_entrega: a?.fecha_entrega ?? '',
    };
}

const form = useForm(formularioInicial());

watch(
    () => props.panel.baja_id,
    () => form.defaults(formularioInicial()).reset(),
);

function guardarAviso() {
    form.put(rutasSalida.aviso.url(props.panel.baja_id), {
        preserveScroll: true,
    });
}

function agregarTestigo() {
    form.testigos.push({ nombre: '', cargo: '' });
}

function quitarTestigo(i: number) {
    form.testigos.splice(i, 1);
}

function generar() {
    procesando.value = true;
    router.post(
        rutasSalida.generar.url(props.panel.baja_id),
        pidiendoVersion.value ? { motivo: motivo.value } : {},
        {
            preserveScroll: true,
            onSuccess: () => {
                pidiendoVersion.value = false;
                motivo.value = '';
            },
            onFinish: () => (procesando.value = false),
        },
    );
}

function subir(
    url: string,
    evento: Event,
    extra: Record<string, boolean> = {},
) {
    const entrada = evento.target as HTMLInputElement;
    const archivo = entrada.files?.[0];
    entrada.value = '';

    if (!archivo) {
        return;
    }

    procesando.value = true;
    router.post(
        url,
        { archivo, ...extra },
        {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => (procesando.value = false),
        },
    );
}

function validarPlazo() {
    router.post(
        rutasSalida.plazo.url(props.panel.baja_id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <section
        class="flex flex-col gap-3 rounded-2xl border-2 border-[var(--mrl-dorado)] bg-card p-4 shadow-sm"
        aria-label="Generar documento de salida"
    >
        <header class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0">
                <h3 class="text-base font-semibold">
                    Generar documento de salida
                </h3>
                <p class="text-xs text-muted-foreground">
                    El documento depende de la forma de salida y se llena con
                    los datos del expediente.
                </p>
            </div>
            <ChipEstado :etiqueta="panel.colaborador" tono="oro" />
        </header>

        <div
            class="grid grid-cols-2 overflow-hidden rounded-xl border border-border/60 sm:grid-cols-4"
            role="group"
            aria-label="Documento según la forma de salida"
        >
            <div
                v-for="o in opciones"
                :key="o.clave"
                :aria-current="
                    panel.documento?.clave === o.clave ? 'true' : undefined
                "
                :class="
                    cn(
                        'border-r border-border/60 px-2 py-1.5 text-center text-xs last:border-r-0',
                        panel.documento?.clave === o.clave
                            ? 'bg-[var(--mrl-petroleo)] font-semibold text-white'
                            : 'text-muted-foreground',
                    )
                "
            >
                <p>{{ o.titulo }}</p>
                <p class="text-[0.7rem] opacity-80">{{ o.detalle }}</p>
            </div>
        </div>

        <p v-if="panel.causal" class="text-sm">
            <span class="text-xs text-muted-foreground">Causal:</span>
            <span class="ml-1 font-medium">{{ panel.causal }}</span>
        </p>

        <form
            v-if="esRescision"
            class="grid gap-3"
            @submit.prevent="guardarAviso"
        >
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="aviso-fundamento"
                        >Causal y fracción (art. 47 LFT)</Label
                    >
                    <Input id="aviso-fundamento" v-model="form.fundamento" />
                    <InputError :message="form.errors.fundamento" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="aviso-fecha">Fecha del aviso</Label>
                    <DatePicker id="aviso-fecha" v-model="form.fecha_aviso" />
                    <InputError :message="form.errors.fecha_aviso" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label for="aviso-que">Hechos (obligatorio): qué ocurrió</Label>
                <Textarea id="aviso-que" v-model="form.que_ocurrio" rows="2" />
                <InputError :message="form.errors.que_ocurrio" />
            </div>
            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="aviso-cuando">Fecha y hora de los hechos</Label>
                    <Input
                        id="aviso-cuando"
                        v-model="form.hechos_en"
                        type="datetime-local"
                    />
                    <InputError :message="form.errors.hechos_en" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="aviso-lugar">Lugar</Label>
                    <Input id="aviso-lugar" v-model="form.lugar" />
                    <InputError :message="form.errors.lugar" />
                </div>
            </div>
            <div class="grid gap-1.5">
                <Label for="aviso-constataron">Quién lo constató</Label>
                <Input id="aviso-constataron" v-model="form.constataron" />
                <InputError :message="form.errors.constataron" />
            </div>
            <div class="grid gap-1.5">
                <Label for="aviso-relato">Relato circunstanciado</Label>
                <Textarea id="aviso-relato" v-model="form.relato" rows="3" />
                <InputError :message="form.errors.relato" />
            </div>

            <fieldset class="grid gap-2">
                <legend class="text-sm font-medium">Testigos</legend>
                <div
                    v-for="(t, i) in form.testigos"
                    :key="i"
                    class="grid grid-cols-[1fr_1fr_auto] gap-2"
                >
                    <Input
                        v-model="t.nombre"
                        :aria-label="`Nombre del testigo ${i + 1}`"
                        placeholder="Nombre"
                    />
                    <Input
                        v-model="t.cargo"
                        :aria-label="`Cargo del testigo ${i + 1}`"
                        placeholder="Cargo"
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="`Quitar testigo ${i + 1}`"
                        @click="quitarTestigo(i)"
                        ><Trash2 class="size-4"
                    /></Button>
                </div>
                <InputError :message="form.errors.testigos" />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    class="w-fit"
                    @click="agregarTestigo"
                    ><Plus class="size-4" />Agregar testigo</Button
                >
            </fieldset>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <span class="text-sm font-medium">Entrega del aviso</span>
                    <div
                        class="grid grid-cols-2 overflow-hidden rounded-xl border border-border/60"
                        role="radiogroup"
                        aria-label="Entrega del aviso"
                    >
                        <button
                            v-for="m in [
                                {
                                    valor: 'personal',
                                    texto: 'Entregado personalmente',
                                },
                                {
                                    valor: 'se_nego',
                                    texto: 'Se negó a recibirlo',
                                },
                            ]"
                            :key="m.valor"
                            type="button"
                            role="radio"
                            :aria-checked="form.metodo_entrega === m.valor"
                            :class="
                                cn(
                                    'px-2 py-2 text-xs font-medium',
                                    form.metodo_entrega === m.valor
                                        ? 'bg-[var(--mrl-petroleo)] text-white'
                                        : 'text-muted-foreground hover:bg-muted',
                                )
                            "
                            @click="form.metodo_entrega = m.valor"
                        >
                            {{ m.texto }}
                        </button>
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="aviso-entrega"
                        >Fecha real de entrega o negativa</Label
                    >
                    <DatePicker
                        id="aviso-entrega"
                        v-model="form.fecha_entrega"
                    />
                    <InputError :message="form.errors.fecha_entrega" />
                </div>
            </div>

            <Button
                type="submit"
                variant="outline"
                class="w-fit"
                :disabled="form.processing || !panel.abierta"
                >Guardar hechos y entrega</Button
            >
        </form>

        <div v-if="esRescision" class="grid gap-1.5">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <span class="text-sm font-medium"
                    >Evidencias ({{ panel.evidencias.length }})</span
                >
                <label
                    v-if="panel.abierta"
                    class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-xl border border-border/60 px-3 text-xs font-medium hover:bg-muted"
                >
                    <FileUp class="size-3.5" />Agregar evidencia
                    <input
                        type="file"
                        class="sr-only"
                        :disabled="procesando"
                        @change="
                            subir(
                                rutasSalida.evidencias.url(panel.baja_id),
                                $event,
                            )
                        "
                    />
                </label>
            </div>
            <ul v-if="panel.evidencias.length" class="grid gap-1 text-xs">
                <li v-for="e in panel.evidencias" :key="e.id">
                    <a
                        :href="e.url"
                        target="_blank"
                        class="underline-offset-2 hover:underline"
                        >{{ e.nombre_original }}</a
                    >
                    <span class="text-muted-foreground">
                        · {{ formatearFechaHora(e.fecha) }}</span
                    >
                </li>
            </ul>
        </div>

        <div
            v-if="panel.plazo"
            class="rounded-xl border-l-4 border-l-destructive bg-danger-soft px-3 py-2 text-xs"
            role="alert"
        >
            <p class="flex items-start gap-1.5 font-semibold text-destructive">
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />{{
                    panel.plazo.mensaje
                }}
            </p>
            <p class="mt-1 text-foreground">{{ panel.plazo.nota }}</p>
            <p
                v-if="panel.plazo.validado"
                class="mt-1 flex items-center gap-1 text-success"
            >
                <CheckCircle2 class="size-3.5" />Validado por
                {{ panel.plazo.validado_por }} ·
                {{ formatearFechaHora(panel.plazo.validado_en) }}
            </p>
            <Button
                v-else-if="panel.puede_validar_plazo"
                type="button"
                size="sm"
                variant="outline"
                class="mt-2"
                @click="validarPlazo"
                >Marcar plazo como validado</Button
            >
        </div>

        <p
            v-if="panel.faltantes.length"
            class="rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
        >
            Falta capturar: {{ panel.faltantes.join(', ') }}.
        </p>

        <div
            v-if="errores.length && !form.hasErrors"
            class="rounded-xl bg-danger-soft px-3 py-2 text-xs text-destructive"
            role="alert"
        >
            <p v-for="(e, i) in errores" :key="i">{{ e }}</p>
        </div>

        <div
            v-if="panel.generado"
            class="flex flex-wrap items-center gap-2 text-xs"
        >
            <ChipEstado
                :etiqueta="
                    panel.generado.firmado ? 'Adjunto' : 'Pendiente de firma'
                "
                :tono="panel.generado.firmado ? 'exito' : 'alerta'"
            />
            <span class="text-muted-foreground"
                >Versión {{ panel.generado.version }} ·
                {{ formatearFechaHora(panel.generado.fecha) }}</span
            >
            <span v-if="panel.generado.desactualizado" class="text-warning"
                >Cambiaron los hechos: genera una nueva versión.</span
            >
        </div>

        <div v-if="pidiendoVersion" class="grid gap-1.5">
            <Label for="salida-motivo">Motivo de la nueva versión</Label>
            <Textarea id="salida-motivo" v-model="motivo" rows="2" />
            <p class="text-xs text-muted-foreground">
                La versión anterior se conserva en el NAS y en el historial.
            </p>
        </div>

        <div v-if="panel.abierta" class="flex flex-wrap justify-end gap-2">
            <Button variant="outline" as-child>
                <a
                    :href="rutasSalida.vistaPrevia.url(panel.baja_id)"
                    target="_blank"
                    ><Eye class="size-4" />Vista previa</a
                >
            </Button>
            <Button
                v-if="!panel.generado?.firmado && !panel.faltantes.length"
                variant="outline"
                @click="corrigiendo = true"
                ><Pencil class="size-4" />Corregir contenido</Button
            >
            <Button
                v-if="!panel.generado || pidiendoVersion"
                :disabled="procesando || panel.faltantes.length > 0"
                @click="generar"
                ><Download class="size-4" />{{
                    panel.generado
                        ? 'Generar nueva versión'
                        : 'Generar PDF para firma'
                }}</Button
            >
            <template v-else>
                <Button as-child>
                    <a :href="rutasSalida.descargar.url(panel.baja_id)"
                        ><Download class="size-4" />Descargar PDF para firma</a
                    >
                </Button>
                <Button
                    v-if="!panel.generado.firmado"
                    variant="outline"
                    @click="pidiendoVersion = true"
                    >Nueva versión</Button
                >
                <label
                    v-if="
                        panel.generado.requiere_huella &&
                        !panel.generado.firmado
                    "
                    class="inline-flex items-center gap-1.5 text-xs"
                >
                    <input v-model="huellaRegistrada" type="checkbox" />
                    Se recabó la huella
                </label>
                <label
                    class="inline-flex h-9 cursor-pointer items-center gap-1.5 rounded-xl bg-[var(--mrl-dorado)] px-3 text-sm font-medium text-white hover:opacity-90"
                >
                    <FileUp class="size-4" />Subir firmado
                    <input
                        type="file"
                        class="sr-only"
                        :disabled="procesando"
                        @change="
                            subir(
                                rutasSalida.firmado.url(panel.baja_id),
                                $event,
                                { huella_registrada: huellaRegistrada },
                            )
                        "
                    />
                </label>
            </template>
        </div>

        <div v-if="panel.ediciones.length" class="grid gap-1 text-xs">
            <p class="font-medium">Correcciones</p>
            <p v-for="e in panel.ediciones" :key="e.id">
                {{ e.campos.join(', ') || 'Valores originales' }}:
                <span class="italic">«{{ e.motivo }}»</span>
                <span class="text-muted-foreground">
                    · {{ e.usuario ?? '—' }} ·
                    {{ formatearFechaHora(e.fecha) }}</span
                >
            </p>
        </div>

        <ul v-if="panel.firmados.length" class="grid gap-1 text-xs">
            <li v-for="f in panel.firmados" :key="f.id">
                <a
                    :href="f.url"
                    target="_blank"
                    class="font-medium underline-offset-2 hover:underline"
                    >{{ f.nombre_original }}</a
                >
                <span class="text-muted-foreground">
                    · firmado cargado por {{ f.subido_por ?? '—' }} ·
                    {{ formatearFechaHora(f.fecha) }}</span
                >
            </li>
        </ul>
    </section>

    <EditorDocumentoSalida v-model:open="corrigiendo" :panel="panel" />
</template>
