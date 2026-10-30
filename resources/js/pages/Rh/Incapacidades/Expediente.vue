<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Download, FileText, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatearFecha } from '@/lib/fechas';
import {
    cancelar,
    corregir,
    historial,
    index,
    pdf,
} from '@/routes/rh/incapacidades';
import {
    show as verDocumento,
    store as subirDocumento,
} from '@/routes/rh/incapacidades/documentos';
import type {
    CadenaIncapacidad,
    IncapacidadItem,
    ReglaDiasNomina,
} from '@/types';

defineProps<{
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

const documentosHistoricos = (cadena: CadenaIncapacidad) =>
    cadena.documentos.filter((d) => !d.vigente);

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
    reemplaza: boolean;
} | null>(null);
const pideFecha = computed(() =>
    ['st7', 'st2', 'st3'].includes(formaDocumento.tipo),
);

function abrirSubida(
    incapacidadId: number,
    tipo: string,
    etiqueta: string,
    reemplaza: boolean,
) {
    formaDocumento.reset();
    formaDocumento.clearErrors();
    formaDocumento.tipo = tipo;
    subiendo.value = { incapacidadId, etiqueta, reemplaza };
}

function enviarDocumento() {
    if (!subiendo.value || formaDocumento.processing) {
        return;
    }

    formaDocumento.post(subirDocumento.url(subiendo.value.incapacidadId), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => (subiendo.value = null),
    });
}

// Corregir / cancelar.
const corrigiendo = ref<IncapacidadItem | null>(null);
const formaCorreccion = useForm({
    serie_folio_imss: '',
    fecha_inicio: '',
    dias: '' as string | number,
    motivo: '',
});

function abrirCorreccion(fila: IncapacidadItem) {
    formaCorreccion.clearErrors();
    formaCorreccion.serie_folio_imss = fila.serie_folio_imss;
    formaCorreccion.fecha_inicio = fila.fecha_inicio;
    formaCorreccion.dias = fila.dias;
    formaCorreccion.motivo = '';
    corrigiendo.value = fila;
}

function enviarCorreccion() {
    if (!corrigiendo.value || formaCorreccion.processing) {
        return;
    }

    formaCorreccion.post(corregir.url(corrigiendo.value.id), {
        preserveScroll: true,
        onSuccess: () => (corrigiendo.value = null),
    });
}

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
                                    <template
                                        v-for="documento in cadena.documentos.filter(
                                            (d) =>
                                                d.tipo === 'certificado' &&
                                                d.incapacidad_id === fila.id &&
                                                d.vigente,
                                        )"
                                        :key="documento.id"
                                    >
                                        <a
                                            v-if="puede.descargar"
                                            :href="
                                                verDocumento.url(documento.id)
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            class="text-primary hover:underline"
                                            >Ver</a
                                        >
                                        <span v-else class="text-success"
                                            >Cargado ✓</span
                                        >
                                    </template>
                                    <button
                                        v-if="
                                            puede.subir &&
                                            fila.estatus !== 'cancelada'
                                        "
                                        type="button"
                                        class="ml-2 text-xs text-muted-foreground hover:underline"
                                        @click="
                                            abrirSubida(
                                                fila.id,
                                                'certificado',
                                                'Certificado de incapacidad',
                                                true,
                                            )
                                        "
                                    >
                                        Reemplazar
                                    </button>
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    <template v-if="fila.puede_corregir">
                                        <button
                                            type="button"
                                            class="text-xs text-primary hover:underline"
                                            @click="abrirCorreccion(fila)"
                                        >
                                            Corregir
                                        </button>
                                        <button
                                            type="button"
                                            class="ml-2 text-xs text-destructive hover:underline"
                                            @click="cancelando = fila"
                                        >
                                            Cancelar
                                        </button>
                                    </template>
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
                            as-child
                            variant="outline"
                            size="sm"
                        >
                            <a
                                :href="
                                    verDocumento.url(
                                        vigente(cadena, espacio.tipo)!.id,
                                    )
                                "
                                target="_blank"
                                rel="noopener"
                                >Ver</a
                            >
                        </Button>
                        <Button
                            v-if="puede.subir && cadena.estatus !== 'cancelada'"
                            variant="outline"
                            size="sm"
                            @click="
                                abrirSubida(
                                    cadena.id,
                                    espacio.tipo,
                                    espacio.etiqueta,
                                    Boolean(vigente(cadena, espacio.tipo)),
                                )
                            "
                        >
                            <Upload class="size-3.5" />
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
                            <a
                                v-if="puede.descargar"
                                :href="verDocumento.url(documento.id)"
                                target="_blank"
                                rel="noopener"
                                class="hover:underline"
                                >{{ documento.nombre_original }}</a
                            >
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
        @update:open="(v) => !v && (subiendo = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{ subiendo?.etiqueta }}</DialogTitle>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviarDocumento">
                <div class="grid gap-1.5">
                    <Label for="archivo">Archivo (PDF o imagen)</Label>
                    <Input
                        id="archivo"
                        type="file"
                        accept=".pdf,.jpg,.jpeg,.png,.webp"
                        @change="
                            formaDocumento.archivo =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                    <p
                        v-if="formaDocumento.errors.archivo"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ formaDocumento.errors.archivo }}
                    </p>
                </div>
                <div v-if="pideFecha" class="grid gap-1.5">
                    <Label for="fecha_documento">Fecha del documento</Label>
                    <DatePicker
                        id="fecha_documento"
                        v-model="formaDocumento.fecha_documento"
                    />
                    <p
                        v-if="formaDocumento.errors.fecha_documento"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ formaDocumento.errors.fecha_documento }}
                    </p>
                </div>
                <div v-if="subiendo?.reemplaza" class="grid gap-1.5">
                    <Label for="motivo_documento">Motivo del reemplazo</Label>
                    <Textarea
                        id="motivo_documento"
                        v-model="formaDocumento.motivo"
                        rows="2"
                    />
                    <p class="text-xs text-muted-foreground">
                        El archivo anterior se conserva como versión anterior.
                    </p>
                </div>
                <p
                    v-if="formaDocumento.errors.motivo"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ formaDocumento.errors.motivo }}
                </p>
                <p
                    v-if="formaDocumento.errors.tipo"
                    class="text-sm text-destructive"
                    data-input-error
                >
                    {{ formaDocumento.errors.tipo }}
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="subiendo = null"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="formaDocumento.processing">
                        <Spinner v-if="formaDocumento.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="corrigiendo !== null"
        @update:open="(v) => !v && (corrigiendo = null)"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Corregir incapacidad</DialogTitle>
            </DialogHeader>
            <form class="grid gap-3" @submit.prevent="enviarCorreccion">
                <div class="grid gap-1.5">
                    <Label for="corr_folio">Serie y folio</Label>
                    <Input
                        id="corr_folio"
                        v-model="formaCorreccion.serie_folio_imss"
                    />
                    <p
                        v-if="formaCorreccion.errors.serie_folio_imss"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ formaCorreccion.errors.serie_folio_imss }}
                    </p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-1.5">
                        <Label for="corr_inicio">Fecha de inicio</Label>
                        <DatePicker
                            id="corr_inicio"
                            v-model="formaCorreccion.fecha_inicio"
                        />
                        <p
                            v-if="formaCorreccion.errors.fecha_inicio"
                            class="text-sm text-destructive"
                            data-input-error
                        >
                            {{ formaCorreccion.errors.fecha_inicio }}
                        </p>
                    </div>
                    <div class="grid gap-1.5">
                        <Label for="corr_dias">Días</Label>
                        <Input
                            id="corr_dias"
                            v-model="formaCorreccion.dias"
                            type="number"
                            min="1"
                        />
                        <p
                            v-if="formaCorreccion.errors.dias"
                            class="text-sm text-destructive"
                            data-input-error
                        >
                            {{ formaCorreccion.errors.dias }}
                        </p>
                    </div>
                </div>
                <div class="grid gap-1.5">
                    <Label for="corr_motivo">Motivo de la corrección</Label>
                    <Textarea
                        id="corr_motivo"
                        v-model="formaCorreccion.motivo"
                        rows="2"
                    />
                    <p
                        v-if="formaCorreccion.errors.motivo"
                        class="text-sm text-destructive"
                        data-input-error
                    >
                        {{ formaCorreccion.errors.motivo }}
                    </p>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="corrigiendo = null"
                        >Cerrar</Button
                    >
                    <Button type="submit" :disabled="formaCorreccion.processing"
                        >Guardar corrección</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>

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
