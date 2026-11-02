<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Brain,
    CheckCircle2,
    Eye,
    FileText,
    FolderOpen,
    Image,
    Inbox,
    Plus,
    RefreshCw,
    Upload,
    UserCheck,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import BandejaCvSelector from '@/components/Rh/BandejaCvSelector.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import {
    claseDeArchivo,
    formatearTamano,
    formatoDeArchivo,
} from '@/lib/archivos';
import { cv } from '@/routes/rh/candidatos';
import { desdeBandeja } from '@/routes/rh/candidatos/cv';
import { store as guardarEvidencias } from '@/routes/rh/candidatos/evidencias';
import type {
    EvidenciaCandidato,
    ExpedienteCandidato,
    LimitesArchivosCandidato,
} from '@/types';

/**
 * Mini expediente del candidato ANTES de la contratación: CV, resultados
 * psicométricos, videos/fotos del socioeconómico y soporte de referencias.
 * Todo vive en el NAS (carpeta candidatos/{id}/…); aquí solo se listan y se
 * abren con el visor embebido. Permite agregar archivos sin cambiar la fase.
 */
const props = defineProps<{
    candidatoId: number;
    expediente: ExpedienteCandidato;
    puedeAdjuntar: boolean;
    limites: LimitesArchivosCandidato;
    /** Ya hay estudio socioeconómico registrado (se le pueden sumar videos). */
    socioeconomicoRegistrado: boolean;
    /** Ya hay al menos una referencia registrada. */
    hayReferencias: boolean;
}>();

const emit = defineEmits<{
    ver: [evidencia: EvidenciaCandidato];
    verCv: [];
}>();

const ACEPTA: Record<string, string> = {
    socioeconomico: '.mp4,.mov,.m4v,.3gp,.webm,.jpg,.jpeg,.png,.webp,.pdf',
    psicometricas: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx',
    referencias: '.pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx',
};

const etapaActiva = ref<{ etapa: string; titulo: string } | null>(null);
const form = useForm({ etapa: '', archivos: [] as File[] });
const formCv = useForm({ cv: null as File | null });

const grupos = computed(() => props.expediente.grupos);

function puedeAgregarA(etapa: string): boolean {
    if (!props.puedeAdjuntar) {
        return false;
    }

    if (etapa === 'socioeconomico') {
        return props.socioeconomicoRegistrado;
    }

    if (etapa === 'referencias') {
        return props.hayReferencias;
    }

    return etapa === 'psicometricas';
}

function abrir(etapa: string, titulo: string) {
    form.reset();
    form.clearErrors();
    form.etapa = etapa;
    etapaActiva.value = { etapa, titulo };
}

function subir() {
    form.post(guardarEvidencias.url(props.candidatoId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => (etapaActiva.value = null),
    });
}

/** Con CV cargado, el área para subir otro solo se abre al pedir «Reemplazar». */
const reemplazandoCv = ref(false);
const archivosCv = ref<File[]>([]);
const errorCv = ref('');
const mostrarCargaCv = computed(
    () => props.puedeAdjuntar && (!props.expediente.cv || reemplazandoCv.value),
);

// Se sube al soltarlo: no hay un segundo botón que olvidar.
watch(archivosCv, (archivos) => {
    const archivo = archivos[0];

    if (!archivo) {
        return;
    }

    errorCv.value = '';
    formCv.cv = archivo;
    formCv.post(cv.url(props.candidatoId), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            reemplazandoCv.value = false;
        },
        onFinish: () => {
            archivosCv.value = [];
        },
    });
});

const formBandeja = useForm<{ archivo: string; cv_bandeja?: string }>({
    archivo: '',
});

function tomarDeBandeja() {
    formBandeja.post(desdeBandeja.url(props.candidatoId), {
        preserveScroll: true,
        onSuccess: () => {
            formBandeja.reset();
            reemplazandoCv.value = false;
        },
    });
}

function icono(ev: EvidenciaCandidato) {
    const clase = claseDeArchivo(ev);

    return clase === 'video' ? Video : clase === 'imagen' ? Image : FileText;
}

const ICONOS_GRUPO: Record<string, Component> = {
    socioeconomico: Video,
    psicometricas: Brain,
    referencias: UserCheck,
};

function iconoGrupo(etapa: string): Component {
    return ICONOS_GRUPO[etapa] ?? FileText;
}

function tonoArchivo(ev: EvidenciaCandidato): string {
    const clase = claseDeArchivo(ev);

    if (clase === 'video') {
        return 'bg-salvia text-primary';
    }

    return clase === 'imagen'
        ? 'bg-info-soft text-info'
        : 'bg-danger-soft text-danger';
}

const errorArchivos = computed(
    () =>
        form.errors.archivos ??
        Object.entries(form.errors).find(([clave]) =>
            clave.startsWith('archivos.'),
        )?.[1] ??
        form.errors.etapa,
);
</script>

<template>
    <section
        class="@container flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm sm:p-5"
        aria-label="Expediente del candidato"
    >
        <header class="flex items-center gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                aria-hidden="true"
            >
                <FolderOpen class="size-5" />
            </span>
            <div class="flex min-w-0 flex-1 items-center gap-1.5">
                <h2 class="truncate text-base font-semibold">
                    Expediente del candidato
                </h2>
                <AyudaBoton
                    titulo="Expediente del candidato"
                    texto="Lo que el candidato presentó durante el proceso (CV, psicométricos, socioeconómico y referencias). Se guarda en el NAS y lo consultan RH, Dirección Comercial y el área administrativa. Agregar archivos no cambia su fase."
                />
            </div>
            <span
                class="flex shrink-0 flex-col items-end leading-none"
                :aria-label="`${expediente.total} archivos`"
            >
                <span class="text-2xl font-bold text-primary tabular-nums">{{
                    expediente.total
                }}</span>
                <span class="text-[11px] text-muted-foreground">archivos</span>
            </span>
        </header>

        <!-- Currículum -->
        <div
            class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3"
        >
            <div class="flex items-center gap-2">
                <FileText class="size-4 text-bronce" />
                <p class="text-sm font-semibold">Currículum</p>
                <span
                    v-if="expediente.cv"
                    class="ml-auto inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-success"
                >
                    <CheckCircle2 class="size-3.5" /> Cargado
                </span>
                <span
                    v-else
                    class="ml-auto inline-flex items-center gap-1 rounded-full bg-warning-soft px-2 py-0.5 text-xs font-medium text-warning"
                >
                    <AlertTriangle class="size-3.5" /> Falta
                </span>
            </div>

            <div
                v-if="expediente.cv"
                class="flex w-full min-w-0 flex-wrap items-center gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
            >
                <button
                    type="button"
                    class="flex min-w-0 flex-1 items-center gap-3 rounded-lg text-left focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :aria-label="`Ver ${expediente.cv.nombre}`"
                    @click="emit('verCv')"
                >
                    <span
                        class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-danger-soft text-danger"
                    >
                        <FileText class="size-5" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium">{{
                            expediente.cv.nombre
                        }}</span>
                        <span class="block text-xs text-muted-foreground">{{
                            [
                                formatoDeArchivo({
                                    nombre: expediente.cv.nombre,
                                }),
                                formatearTamano(expediente.cv.tamano),
                            ]
                                .filter(Boolean)
                                .join(' · ')
                        }}</span>
                    </span>
                </button>
                <div class="flex shrink-0 gap-2">
                    <Button class="h-10" @click="emit('verCv')">
                        <Eye class="size-4" /> Ver CV
                    </Button>
                    <Button
                        v-if="puedeAdjuntar"
                        variant="outline"
                        class="h-10"
                        :aria-expanded="reemplazandoCv"
                        @click="reemplazandoCv = !reemplazandoCv"
                    >
                        <RefreshCw class="size-4" />
                        {{ reemplazandoCv ? 'Conservar este' : 'Reemplazar' }}
                    </Button>
                </div>
            </div>

            <div
                v-if="mostrarCargaCv"
                class="grid gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3"
            >
                <PeopleFileDropzone
                    v-model="archivosCv"
                    accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.webp"
                    :max-size-mb="limites.max_cv_mb"
                    :loading="formCv.processing"
                    :label="
                        expediente.cv
                            ? 'Arrastra el CV nuevo aquí'
                            : 'Arrastra el CV aquí'
                    "
                    :hint="`PDF, Word o imagen · Máximo ${limites.max_cv_mb} MB · se guarda al soltarlo`"
                    @error="(m) => (errorCv = m)"
                />
                <div
                    v-if="formCv.processing"
                    class="flex flex-col gap-1.5"
                    role="status"
                >
                    <div class="flex items-center justify-between text-sm">
                        <span class="flex items-center gap-2 font-medium">
                            <Spinner /> Subiendo CV…
                        </span>
                        <span
                            v-if="formCv.progress"
                            class="font-bold text-primary tabular-nums"
                            >{{ formCv.progress.percentage }}%</span
                        >
                    </div>
                    <Progress
                        :model-value="formCv.progress?.percentage ?? 0"
                        class="h-2"
                    />
                </div>
                <InputError :message="errorCv || formCv.errors.cv" />

                <!-- CV descargado de WhatsApp: ya está en la bandeja del NAS. -->
                <div
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span class="h-px flex-1 bg-border" />
                    o tómalo de la bandeja de WhatsApp
                    <span class="h-px flex-1 bg-border" />
                </div>
                <div class="grid min-w-0 gap-2">
                    <BandejaCvSelector v-model="formBandeja.archivo" />
                    <InputError
                        :message="
                            formBandeja.errors.cv_bandeja ??
                            formBandeja.errors.archivo
                        "
                    />
                    <Button
                        v-if="formBandeja.archivo"
                        class="h-10 justify-self-start"
                        :disabled="formBandeja.processing"
                        @click="tomarDeBandeja"
                    >
                        <Spinner v-if="formBandeja.processing" />
                        <Inbox v-else class="size-4" />
                        {{
                            expediente.cv
                                ? 'Reemplazar con este CV'
                                : 'Guardar como CV'
                        }}
                    </Button>
                </div>
            </div>
        </div>

        <!-- Grupos de evidencias -->
        <div class="grid grid-cols-1 gap-3 @2xl:grid-cols-3">
            <div
                v-for="grupo in grupos"
                :key="grupo.clave"
                class="flex min-w-0 flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3"
            >
                <div class="flex items-center gap-2.5">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                        :class="
                            grupo.archivos.length
                                ? 'bg-success-soft text-success'
                                : 'bg-card text-muted-foreground'
                        "
                        aria-hidden="true"
                    >
                        <component
                            :is="iconoGrupo(grupo.etapa)"
                            class="size-4"
                        />
                    </span>
                    <p
                        class="min-w-0 flex-1 text-sm leading-tight font-semibold"
                    >
                        {{ grupo.titulo }}
                    </p>
                    <span
                        class="text-xl font-bold tabular-nums"
                        :class="
                            grupo.archivos.length
                                ? 'text-primary'
                                : 'text-muted-foreground/60'
                        "
                        >{{ grupo.archivos.length }}</span
                    >
                </div>

                <ul v-if="grupo.archivos.length" class="flex flex-col gap-1.5">
                    <li v-for="ev in grupo.archivos" :key="ev.id">
                        <button
                            type="button"
                            class="flex w-full min-w-0 tarjeta-interactiva items-center gap-2.5 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-2.5 py-2 text-left"
                            :aria-label="`Ver ${ev.nombre}`"
                            @click="emit('ver', ev)"
                        >
                            <span
                                class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                                :class="tonoArchivo(ev)"
                            >
                                <component :is="icono(ev)" class="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-xs font-medium"
                                    >{{ ev.nombre }}</span
                                >
                                <span
                                    class="block text-[11px] text-muted-foreground"
                                    >{{ formatearTamano(ev.tamano) }}</span
                                >
                            </span>
                            <Eye class="size-4 shrink-0 text-primary" />
                        </button>
                    </li>
                </ul>
                <p
                    v-else
                    class="rounded-xl border border-dashed border-[var(--mrl-borde)] px-3 py-3 text-center text-xs text-muted-foreground"
                >
                    Sin archivos
                </p>

                <Button
                    v-if="puedeAgregarA(grupo.etapa)"
                    variant="outline"
                    class="mt-auto h-10 w-full"
                    @click="abrir(grupo.etapa, grupo.titulo)"
                >
                    <Plus class="size-4" /> Agregar
                </Button>
            </div>
        </div>
    </section>

    <Dialog
        :open="etapaActiva !== null"
        @update:open="(abierto) => !abierto && (etapaActiva = null)"
    >
        <DialogContent
            class="max-h-[100svh] content-start overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-w-lg"
        >
            <DialogHeader>
                <DialogTitle>Agregar a «{{ etapaActiva?.titulo }}»</DialogTitle>
                <DialogDescription>
                    Puedes elegir varios archivos.
                </DialogDescription>
            </DialogHeader>

            <PeopleFileDropzone
                v-model="form.archivos"
                multiple
                :accept="
                    ACEPTA[etapaActiva?.etapa ?? ''] ?? ACEPTA.psicometricas
                "
                :max-size-mb="
                    etapaActiva?.etapa === 'socioeconomico'
                        ? limites.max_video_mb
                        : limites.max_documento_mb
                "
                :loading="form.processing"
                :label="
                    etapaActiva?.etapa === 'socioeconomico'
                        ? 'Arrastra videos, fotos o PDF'
                        : 'Arrastra los archivos'
                "
            />
            <div
                v-if="form.progress"
                class="flex flex-col gap-1.5"
                role="status"
            >
                <div class="flex items-center justify-between text-sm">
                    <span class="flex items-center gap-2 font-medium">
                        <Spinner /> Subiendo…
                    </span>
                    <span class="font-bold text-primary tabular-nums"
                        >{{ form.progress.percentage }}%</span
                    >
                </div>
                <Progress
                    :model-value="form.progress.percentage ?? 0"
                    class="h-2.5"
                />
            </div>
            <InputError :message="errorArchivos" />

            <DialogFooter class="gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    @click="etapaActiva = null"
                    >Cancelar</Button
                >
                <Button
                    size="lg"
                    :disabled="form.processing || form.archivos.length === 0"
                    @click="subir"
                >
                    <Spinner v-if="form.processing" />
                    <Upload v-else class="size-4" />
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
