<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Ban,
    Download,
    Eye,
    FileText,
    RefreshCw,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import BotonCorregir from '@/components/correcciones/BotonCorregir.vue';
import SiguientePasoPanel from '@/components/flujos/SiguientePaso.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
import { formatearFecha } from '@/lib/fechas';
import { cancelar, historial, index, pdf } from '@/routes/rh/incapacidades';
import {
    show as verDocumento,
    store as subirDocumento,
} from '@/routes/rh/incapacidades/documentos';
import type {
    ArchivoPrevisualizable,
    SiguientePaso,
    CadenaIncapacidad,
    DocumentoIncapacidad,
    IncapacidadItem,
    ReglaDiasNomina,
} from '@/types';

const props = defineProps<{
    colaborador: {
        id: number;
        nombre: string;
        puesto: string | null;
        sucursal: string | null;
    };
    cadenas: CadenaIncapacidad[];
    impacto: {
        etiqueta: string;
        dias_incapacidad: number;
        dias_naturales: number;
    };
    regla: ReglaDiasNomina;
    puede: { subir: boolean; corregir: boolean; descargar: boolean };
    pdfRaizId: number | null;
    siguientePaso: SiguientePaso;
    mimes: string[];
    maxKb: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Permanencia', href: '' },
            { title: 'Incapacidades', href: index.url() },
            { title: 'Expediente', href: '' },
        ],
    },
});

const CLASE_PUNTO: Record<string, string> = {
    evento: 'bg-destructive',
    incapacidad: 'bg-info',
    documento: 'bg-bronce',
    pendiente: 'bg-muted-foreground/50',
    cancelada: 'bg-muted-foreground/30',
};

const CLASE_ESTATUS: Record<string, string> = {
    activa: 'bg-warning-soft text-warning',
    concluida: 'bg-success-soft text-success',
    cancelada: 'bg-muted text-muted-foreground',
};

const ESPACIOS = [
    {
        tipo: 'st7',
        etiqueta: 'ST-7 · Aviso para calificar probable riesgo',
        ayuda: 'Se carga una vez por cada riesgo de trabajo.',
    },
    {
        tipo: 'st2',
        etiqueta: 'ST-2 · Dictamen de alta',
        ayuda: 'Al cargarlo, el riesgo pasa a Concluida.',
    },
    {
        tipo: 'st3',
        etiqueta: 'ST-3 · Dictamen de incapacidad permanente',
        ayuda: 'Opcional: solo si hay secuela con porcentaje de valuación.',
    },
] as const;

function vigente(cadena: CadenaIncapacidad, tipo: string) {
    return cadena.documentos.find((d) => d.tipo === tipo && d.vigente);
}

function certificadoDe(cadena: CadenaIncapacidad, incapacidadId: number) {
    return cadena.documentos.find(
        (d) =>
            d.tipo === 'certificado' &&
            d.incapacidad_id === incapacidadId &&
            d.vigente,
    );
}

const documentosHistoricos = (cadena: CadenaIncapacidad) =>
    cadena.documentos.filter((d) => !d.vigente);

// Visor inline (PDF e imágenes) con todos los documentos de la cadena.
const visor = ref<{
    archivos: ArchivoPrevisualizable[];
    indice: number;
} | null>(null);

function aPrevisualizable(d: DocumentoIncapacidad): ArchivoPrevisualizable {
    return {
        clave: `doc-${d.id}`,
        nombre: d.nombre_original,
        previewUrl: verDocumento.url(d.id),
        mime: d.mime,
        tamano: d.tamano,
        etiqueta: d.vigente ? d.etiqueta : `${d.etiqueta} (versión anterior)`,
        fecha: d.fecha_documento ?? d.cargado_en,
        subidoPor: d.cargado_por,
    };
}

function verDocumentoEnVisor(
    cadena: CadenaIncapacidad,
    documento: DocumentoIncapacidad,
) {
    const lista = [
        ...cadena.documentos.filter((d) => d.vigente),
        ...documentosHistoricos(cadena),
    ];

    visor.value = {
        archivos: lista.map(aPrevisualizable),
        indice: Math.max(
            0,
            lista.findIndex((d) => d.id === documento.id),
        ),
    };
}

// Subir / reemplazar documento.
const formaDocumento = useForm({
    tipo: '',
    archivo: null as File | null,
    fecha_documento: '',
    motivo: '',
});
const subiendo = ref<{
    incapacidadId: number;
    etiqueta: string;
    reemplaza: DocumentoIncapacidad | null;
} | null>(null);
const pideFecha = computed(() =>
    ['st7', 'st2', 'st3'].includes(formaDocumento.tipo),
);
const fechaObligatoria = computed(() => formaDocumento.tipo === 'st2');
const maxMb = computed(() => Math.floor(props.maxKb / 1024));
const aceptar = computed(() => props.mimes.map((m) => `.${m}`).join(','));
const ayudaArchivo = computed(
    () =>
        `${props.mimes.map((m) => m.toUpperCase()).join(', ')} · Máximo ${maxMb.value} MB`,
);
const progresoDocumento = computed(() =>
    formaDocumento.progress ? (formaDocumento.progress.percentage ?? 0) : null,
);
/** Faltantes que se avisan antes de enviar (el servidor los vuelve a exigir). */
const faltaParaEnviar = computed(() => {
    if (!formaDocumento.archivo) {
        return 'Elige el archivo.';
    }

    if (fechaObligatoria.value && !formaDocumento.fecha_documento) {
        return 'Captura la fecha del dictamen de alta.';
    }

    if (subiendo.value?.reemplaza && formaDocumento.motivo.trim().length < 1) {
        return 'Indica el motivo del reemplazo.';
    }

    return null;
});
// Errores del servidor sin campo propio en el diálogo (cancelada, NAS…).
const errorGeneralDocumento = computed(() => {
    const errores = formaDocumento.errors as Record<string, string | undefined>;

    return errores.incapacidad ?? errores.tipo ?? null;
});

function abrirSubida(
    incapacidadId: number,
    tipo: string,
    etiqueta: string,
    reemplaza: DocumentoIncapacidad | null,
) {
    formaDocumento.reset();
    formaDocumento.clearErrors();
    formaDocumento.tipo = tipo;
    subiendo.value = { incapacidadId, etiqueta, reemplaza };
}

function cerrarSubida() {
    if (formaDocumento.processing) {
        return;
    }

    subiendo.value = null;
}

function elegirArchivo(archivos: File[]) {
    formaDocumento.archivo = archivos[0] ?? null;
    formaDocumento.clearErrors('archivo');
}

function enviarDocumento() {
    if (!subiendo.value || formaDocumento.processing || faltaParaEnviar.value) {
        return;
    }

    formaDocumento.post(subirDocumento.url(subiendo.value.incapacidadId), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            subiendo.value = null;
            formaDocumento.reset();
        },
    });
}

// Cancelar (la corrección va por BotonCorregir).
const cancelando = ref<IncapacidadItem | null>(null);
const formaCancelacion = useForm({ motivo: '' });

function enviarCancelacion() {
    if (!cancelando.value || formaCancelacion.processing) {
        return;
    }

    formaCancelacion.post(cancelar.url(cancelando.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            cancelando.value = null;
            formaCancelacion.reset();
        },
    });
}
</script>

<template>
    <Head :title="`Incapacidades · ${colaborador.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-4">
        <SiguientePasoPanel compacto :paso="siguientePaso" />
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-lg font-semibold">{{ colaborador.nombre }}</h1>
                <p class="text-sm text-muted-foreground">
                    {{ colaborador.puesto ?? 'Sin puesto' }} ·
                    {{ colaborador.sucursal ?? 'Sin sucursal' }}
                </p>
            </div>
            <Button
                v-if="pdfRaizId !== null"
                as-child
                variant="outline"
                size="sm"
            >
                <Link :href="historial.url(colaborador.id)">
                    Ver todo su historial
                </Link>
            </Button>
        </div>

        <p
            v-if="!cadenas.length"
            class="rounded-xl border border-border/60 bg-card p-6 text-center text-sm text-muted-foreground"
        >
            Esta persona no tiene incapacidades registradas.
        </p>

        <section
            v-for="cadena in cadenas"
            :key="cadena.id"
            class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_360px]"
        >
            <div
                class="flex flex-col gap-4 rounded-xl border border-border/60 bg-card p-5 shadow-sm"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="font-semibold">
                        {{ cadena.tipo_etiqueta }}
                        <template v-if="cadena.subtipo_etiqueta">
                            · {{ cadena.subtipo_etiqueta }}
                        </template>
                    </h2>
                    <span
                        class="rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="CLASE_ESTATUS[cadena.estatus]"
                        >{{ cadena.estatus_etiqueta }}</span
                    >
                    <span
                        v-if="cadena.cuenta_siniestralidad === false"
                        class="rounded-full bg-crema px-2 py-0.5 text-xs font-medium text-bronce"
                        >Aparte de siniestralidad</span
                    >
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead>
                            <tr
                                class="bg-crema/50 text-left text-xs tracking-wide text-muted-foreground uppercase"
                            >
                                <th class="px-3 py-2">Secuencia</th>
                                <th class="px-3 py-2">Serie y folio</th>
                                <th class="px-3 py-2">Inicio</th>
                                <th class="px-3 py-2 text-right">Días</th>
                                <th class="px-3 py-2">Término</th>
                                <th class="px-3 py-2">Certificado</th>
                                <th class="px-3 py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="fila in cadena.incapacidades"
                                :key="fila.id"
                                class="border-t border-border/60"
                                :class="
                                    fila.estatus === 'cancelada'
                                        ? 'opacity-60'
                                        : ''
                                "
                            >
                                <td class="px-3 py-2">
                                    {{ fila.secuencia_etiqueta }}
                                    <p
                                        v-if="fila.motivo_cancelacion"
                                        class="text-xs text-muted-foreground"
                                    >
                                        Cancelada: {{ fila.motivo_cancelacion }}
                                    </p>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ fila.serie_folio_imss }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ formatearFecha(fila.fecha_inicio) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ fila.dias }}
                                </td>
                                <td
                                    class="px-3 py-2 font-semibold whitespace-nowrap"
                                >
                                    {{ formatearFecha(fila.fecha_fin) }}
                                </td>
                                <td class="px-3 py-2">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <template
                                            v-if="
                                                certificadoDe(cadena, fila.id)
                                            "
                                        >
                                            <Button
                                                v-if="puede.descargar"
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                :title="
                                                    certificadoDe(
                                                        cadena,
                                                        fila.id,
                                                    )!.nombre_original
                                                "
                                                @click="
                                                    verDocumentoEnVisor(
                                                        cadena,
                                                        certificadoDe(
                                                            cadena,
                                                            fila.id,
                                                        )!,
                                                    )
                                                "
                                            >
                                                <Eye class="size-3.5" />
                                                Ver
                                            </Button>
                                            <span
                                                v-else
                                                class="rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success"
                                                >Cargado ✓</span
                                            >
                                        </template>
                                        <span
                                            v-else
                                            class="rounded-full bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning"
                                            >Sin certificado</span
                                        >
                                        <Button
                                            v-if="
                                                puede.subir &&
                                                fila.estatus !== 'cancelada'
                                            "
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="
                                                abrirSubida(
                                                    fila.id,
                                                    'certificado',
                                                    'Certificado de incapacidad',
                                                    certificadoDe(
                                                        cadena,
                                                        fila.id,
                                                    ) ?? null,
                                                )
                                            "
                                        >
                                            <RefreshCw
                                                v-if="
                                                    certificadoDe(
                                                        cadena,
                                                        fila.id,
                                                    )
                                                "
                                                class="size-3.5"
                                            />
                                            <Upload v-else class="size-3.5" />
                                            {{
                                                certificadoDe(cadena, fila.id)
                                                    ? 'Reemplazar'
                                                    : 'Subir'
                                            }}
                                        </Button>
                                    </div>
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    <div
                                        v-if="fila.puede_corregir"
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <BotonCorregir
                                            dominio="incapacidad"
                                            :sujeto-id="fila.id"
                                            variante="ghost"
                                        />
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            class="text-destructive hover:text-destructive"
                                            @click="cancelando = fila"
                                        >
                                            <Ban class="size-3.5" />
                                            Cancelar
                                        </Button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div v-if="cadena.tipo === 'riesgo_trabajo'" class="grid gap-2">
                    <h3 class="text-sm font-medium">Documentos del riesgo</h3>
                    <div
                        v-for="espacio in ESPACIOS"
                        :key="espacio.tipo"
                        class="flex flex-wrap items-center gap-3 rounded-lg border border-border/60 p-3"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold">
                                {{ espacio.etiqueta }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ espacio.ayuda }}
                            </p>
                        </div>
                        <span
                            class="rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                vigente(cadena, espacio.tipo)
                                    ? 'bg-success-soft text-success'
                                    : espacio.tipo === 'st3'
                                      ? 'bg-muted text-muted-foreground'
                                      : 'bg-warning-soft text-warning'
                            "
                            >{{
                                vigente(cadena, espacio.tipo)
                                    ? 'Cargado ✓'
                                    : espacio.tipo === 'st3'
                                      ? 'Opcional'
                                      : 'Pendiente'
                            }}</span
                        >
                        <Button
                            v-if="
                                vigente(cadena, espacio.tipo) && puede.descargar
                            "
                            type="button"
                            variant="outline"
                            size="sm"
                            :title="
                                vigente(cadena, espacio.tipo)!.nombre_original
                            "
                            @click="
                                verDocumentoEnVisor(
                                    cadena,
                                    vigente(cadena, espacio.tipo)!,
                                )
                            "
                        >
                            <Eye class="size-3.5" />
                            Ver
                        </Button>
                        <Button
                            v-if="puede.subir && cadena.estatus !== 'cancelada'"
                            type="button"
                            :variant="
                                vigente(cadena, espacio.tipo)
                                    ? 'ghost'
                                    : 'default'
                            "
                            size="sm"
                            :disabled="
                                espacio.tipo === 'st2' &&
                                !vigente(cadena, 'st7')
                            "
                            :title="
                                espacio.tipo === 'st2' &&
                                !vigente(cadena, 'st7')
                                    ? 'Carga primero el ST-7'
                                    : undefined
                            "
                            @click="
                                abrirSubida(
                                    cadena.id,
                                    espacio.tipo,
                                    espacio.etiqueta,
                                    vigente(cadena, espacio.tipo) ?? null,
                                )
                            "
                        >
                            <RefreshCw
                                v-if="vigente(cadena, espacio.tipo)"
                                class="size-3.5"
                            />
                            <Upload v-else class="size-3.5" />
                            {{
                                vigente(cadena, espacio.tipo)
                                    ? 'Reemplazar'
                                    : 'Subir'
                            }}
                        </Button>
                    </div>
                </div>

                <div v-if="documentosHistoricos(cadena).length">
                    <h3 class="text-sm font-medium">Versiones anteriores</h3>
                    <ul class="mt-1 text-xs text-muted-foreground">
                        <li
                            v-for="documento in documentosHistoricos(cadena)"
                            :key="documento.id"
                        >
                            {{ documento.etiqueta }} ·
                            <button
                                v-if="puede.descargar"
                                type="button"
                                class="text-primary hover:underline"
                                @click="verDocumentoEnVisor(cadena, documento)"
                            >
                                {{ documento.nombre_original }}
                            </button>
                            <template v-else>{{
                                documento.nombre_original
                            }}</template>
                        </li>
                    </ul>
                </div>
            </div>

            <aside class="flex flex-col gap-3">
                <div
                    class="rounded-xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <h3 class="font-semibold">
                        <template v-if="cadena.riesgo_folio">
                            Historial del riesgo {{ cadena.riesgo_folio }}
                        </template>
                        <template v-else>Historial</template>
                    </h3>
                    <ol class="mt-3 flex flex-col">
                        <li
                            v-for="(evento, i) in cadena.linea_de_tiempo"
                            :key="i"
                            class="relative flex gap-3 pb-3"
                        >
                            <span
                                class="mt-1 size-3 shrink-0 rounded-full"
                                :class="CLASE_PUNTO[evento.clase]"
                                aria-hidden="true"
                            />
                            <div class="min-w-0">
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        evento.pendiente
                                            ? 'Pendiente'
                                            : formatearFecha(evento.fecha)
                                    }}
                                </p>
                                <p class="text-sm font-semibold">
                                    {{ evento.titulo }}
                                </p>
                                <p
                                    v-if="evento.detalle"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ evento.detalle }}
                                </p>
                            </div>
                        </li>
                    </ol>
                    <p
                        class="flex items-center justify-between rounded-lg bg-crema/50 px-3 py-2 text-sm"
                    >
                        Días a la fecha
                        <strong class="tabular-nums">{{
                            cadena.dias_totales
                        }}</strong>
                    </p>
                    <Button
                        v-if="puede.descargar"
                        as-child
                        variant="outline"
                        size="sm"
                        class="mt-3"
                    >
                        <a :href="pdf.url(cadena.id)">
                            <Download class="size-3.5" />
                            Descargar expediente (PDF)
                        </a>
                    </Button>
                </div>
            </aside>
        </section>

        <div
            v-if="cadenas.length"
            class="rounded-xl border border-border/60 bg-card p-4 shadow-sm"
        >
            <h2 class="flex items-center gap-1.5 font-semibold">
                <FileText class="size-4" />
                Impacto en nómina
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ impacto.etiqueta }}:
                <strong class="text-destructive"
                    >{{ impacto.dias_incapacidad }} día(s)</strong
                >
                de incapacidad de {{ impacto.dias_naturales }} naturales.
            </p>
            <p
                v-if="regla.aviso"
                class="mt-2 flex gap-1.5 rounded-lg bg-warning-soft p-2.5 text-xs text-warning"
            >
                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    <strong>{{ regla.etiqueta }}.</strong> {{ regla.aviso }}
                </span>
            </p>
        </div>
    </div>

    <Dialog
        :open="subiendo !== null"
        @update:open="(v) => !v && cerrarSubida()"
    >
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>
                    {{ subiendo?.reemplaza ? 'Reemplazar' : 'Subir' }}
                    {{ subiendo?.etiqueta }}
                </DialogTitle>
                <DialogDescription>
                    <template v-if="subiendo?.reemplaza">
                        Sustituye a «{{ subiendo.reemplaza.nombre_original }}».
                        El archivo anterior no se borra: queda en «Versiones
                        anteriores».
                    </template>
                    <template v-else>
                        Revisa la vista previa antes de guardar: el archivo
                        queda en el expediente del colaborador.
                    </template>
                </DialogDescription>
            </DialogHeader>
            <form class="grid gap-4" @submit.prevent="enviarDocumento">
                <PeopleFileDropzone
                    id="archivo"
                    vista-previa
                    :model-value="
                        formaDocumento.archivo ? [formaDocumento.archivo] : []
                    "
                    :accept="aceptar"
                    :max-size-mb="maxMb"
                    :hint="ayudaArchivo"
                    :loading="formaDocumento.processing"
                    :progreso="progresoDocumento"
                    :error="formaDocumento.errors.archivo ?? null"
                    label="Arrastra el documento aquí"
                    @update:model-value="elegirArchivo"
                />
                <div v-if="pideFecha" class="grid gap-1.5">
                    <Label for="fecha_documento">
                        Fecha del documento
                        <span v-if="fechaObligatoria" class="text-destructive"
                            >*</span
                        >
                        <span v-else class="font-normal text-muted-foreground"
                            >(opcional)</span
                        >
                    </Label>
                    <DatePicker
                        id="fecha_documento"
                        v-model="formaDocumento.fecha_documento"
                    />
                    <p
                        v-if="fechaObligatoria"
                        class="text-xs text-muted-foreground"
                    >
                        Con la fecha del alta el riesgo pasa a Concluida.
                    </p>
                    <p
                        v-if="formaDocumento.errors.fecha_documento"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ formaDocumento.errors.fecha_documento }}
                    </p>
                </div>
                <div v-if="subiendo?.reemplaza" class="grid gap-1.5">
                    <Label for="motivo_documento">
                        Motivo del reemplazo
                        <span class="text-destructive">*</span>
                    </Label>
                    <Textarea
                        id="motivo_documento"
                        v-model="formaDocumento.motivo"
                        rows="2"
                        maxlength="500"
                        placeholder="Ej. el certificado anterior estaba ilegible"
                    />
                    <p
                        v-if="formaDocumento.errors.motivo"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ formaDocumento.errors.motivo }}
                    </p>
                </div>
                <p
                    v-if="errorGeneralDocumento"
                    class="flex items-start gap-1.5 rounded-lg bg-danger-soft p-2.5 text-sm text-destructive"
                    role="alert"
                    data-input-error
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ errorGeneralDocumento }}
                </p>
                <DialogFooter class="items-center gap-2">
                    <p
                        v-if="faltaParaEnviar && !formaDocumento.processing"
                        class="mr-auto text-xs text-muted-foreground"
                    >
                        {{ faltaParaEnviar }}
                    </p>
                    <Button
                        type="button"
                        variant="secondary"
                        :disabled="formaDocumento.processing"
                        @click="cerrarSubida"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            formaDocumento.processing || !!faltaParaEnviar
                        "
                    >
                        <Spinner v-if="formaDocumento.processing" />
                        <template v-if="formaDocumento.processing">
                            Subiendo…
                            {{
                                progresoDocumento !== null
                                    ? `${Math.round(progresoDocumento)}%`
                                    : ''
                            }}
                        </template>
                        <template v-else>
                            <RefreshCw
                                v-if="subiendo?.reemplaza"
                                class="size-3.5"
                            />
                            <Upload v-else class="size-3.5" />
                            {{
                                subiendo?.reemplaza
                                    ? 'Reemplazar documento'
                                    : 'Subir documento'
                            }}
                        </template>
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <DocumentPreviewDialog
        v-if="visor"
        :open="visor !== null"
        :archivos="visor.archivos"
        :indice="visor.indice"
        @update:open="(v) => !v && (visor = null)"
        @update:indice="(i) => visor && (visor.indice = i)"
    />

    <Dialog
        :open="cancelando !== null"
        @update:open="(v) => !v && (cancelando = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Cancelar incapacidad</DialogTitle>
            </DialogHeader>
            <p class="text-sm text-muted-foreground">
                El registro y sus documentos se conservan; deja de contar en
                nómina y el folio se libera para recapturarlo.
            </p>
            <div class="grid gap-1.5">
                <Label for="canc_motivo">Motivo</Label>
                <Textarea
                    id="canc_motivo"
                    v-model="formaCancelacion.motivo"
                    rows="2"
                />
                <p
                    v-if="formaCancelacion.errors.motivo"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ formaCancelacion.errors.motivo }}
                </p>
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="cancelando = null"
                    >Cerrar</Button
                >
                <Button
                    variant="destructive"
                    :disabled="formaCancelacion.processing"
                    @click="enviarCancelacion"
                >
                    Cancelar incapacidad
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
