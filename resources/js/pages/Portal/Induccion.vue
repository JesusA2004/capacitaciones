<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Clock,
    Eye,
    Volume2,
    Video as VideoIcon,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import MaterialInduccionVistaPreviaRh from '@/components/ciclo/MaterialInduccionVistaPreviaRh.vue';
import PresentacionInduccionRh from '@/components/ciclo/PresentacionInduccionRh.vue';
import ReproductorVideoInduccion from '@/components/ciclo/ReproductorVideoInduccion.vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import { postJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { miProceso } from '@/routes/portal';
import { contenido as evaluacionInduccion } from '@/routes/portal/induccion';
import { vistaPrevia as vistaPreviaEvaluacion } from '@/routes/rh/onboarding/induccion';
import type {
    AccesoInduccionColaborador,
    MaterialInduccion,
    SesionInduccionPantalla,
} from '@/types';

/**
 * Inducción Mr. Lana = el PDF OFICIAL (docs/onboarding), dibujado página por
 * página con pdf.js, más —entre las hojas y la evaluación— un material de
 * explicación (audio XOR video):
 *  - «Presentar inducción» (RH): repaso del PDF + gestión del material que el
 *    colaborador verá solo. (El flujo de «sesión en vivo» quedó tras un flag.)
 *  - «Mi espacio» (colaborador): ve las hojas y el material por su cuenta. Si
 *    el material es VIDEO, su evaluación se desbloquea al verlo completo
 *    (anti-adelanto respaldado por el servidor); si es AUDIO, es narración que
 *    acompaña las hojas y no bloquea nada.
 */
const props = defineProps<{
    /** null cuando el colaborador no puede verla todavía / ya no. */
    pdf: { url: string; titulo: string; total_paginas: number } | null;
    modoPresentacion?: boolean;
    /** RH (legacy): sesión de presentación en vivo. */
    sesionRh?: SesionInduccionPantalla | null;
    /** RH: material de explicación vigente. */
    materialRh?: {
        tipo: string;
        tipo_etiqueta: string;
        recurso_nombre: string | null;
        recurso_estado: string;
        duracion_segundos: number | null;
    } | null;
    /** RH: endpoints para cargar/retirar material. */
    rutasMaterial?: { store: string; destroy: string } | null;
    /** Colaborador: cuándo puede ver la inducción. */
    acceso?: AccesoInduccionColaborador | null;
    /** Colaborador: avance institucional titular. */
    avanceId?: number | null;
    /** Colaborador: material de explicación activo. */
    material?: MaterialInduccion | null;
    /** Colaborador: si el servidor ya tiene el video por completo. */
    videoCompletado?: boolean;
    /** Colaborador: endpoints ya resueltos. */
    rutas?: {
        pagina: string;
        terminar: string;
        video_iniciar: string;
        video_heartbeat: string;
        audio_progreso: string;
    } | null;
}>();

// `layout` como función: defineOptions() se compila fuera de setup() y no
// puede leer `props` (ver Rh/Importaciones/Show.vue).
defineOptions({
    layout: (pageProps: { modoPresentacion?: boolean }) => ({
        breadcrumbs: pageProps.modoPresentacion
            ? [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Presentar inducción', href: '' },
              ]
            : [
                  { title: 'Inicio', href: dashboard() },
                  { title: 'Mis pendientes', href: miProceso() },
                  { title: 'Inducción Mr. Lana', href: '' },
              ],
    }),
});

const muestraVisor = computed(() => {
    if (props.pdf === null) {
        return false;
    }

    if (props.modoPresentacion) {
        return true;
    }

    return props.acceso?.puede_ver_contenido ?? false;
});

// El video de explicación bloquea la evaluación hasta verse completo.
const videoListo = ref(props.videoCompletado ?? false);
const esVideo = computed(() => props.material?.tipo === 'video');
const esAudio = computed(() => props.material?.tipo === 'audio');
const puedeTerminar = computed(() => !esVideo.value || videoListo.value);

function registrarPagina(numero: number) {
    if (props.rutas) {
        void postJson(props.rutas.pagina, { pagina: numero }).catch(() => {});
    }
}

function registrarAudio(segundos: number) {
    if (props.rutas) {
        void postJson(props.rutas.audio_progreso, {
            posicion_segundos: Math.floor(segundos),
        }).catch(() => {});
    }
}

function alTerminarVideo() {
    videoListo.value = true;
}

function terminar() {
    if (props.rutas && puedeTerminar.value) {
        router.post(props.rutas.terminar);
    }
}

// --- RH: carga de material ---
const formMaterial = useForm<{ tipo: 'audio' | 'video'; archivo: File | null }>(
    { tipo: 'video', archivo: null },
);

function subirMaterial() {
    if (props.rutasMaterial) {
        formMaterial.post(props.rutasMaterial.store, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => formMaterial.reset('archivo'),
        });
    }
}

function retirarMaterial() {
    if (props.rutasMaterial) {
        router.delete(props.rutasMaterial.destroy, { preserveScroll: true });
    }
}
</script>

<template>
    <Head
        :title="modoPresentacion ? 'Presentar inducción' : 'Inducción Mr. Lana'"
    />

    <!-- RH (legacy): sesión de presentación en vivo. -->
    <div v-if="sesionRh && !pdf" class="pagina-ancha flex flex-col gap-4 pb-10">
        <PresentacionInduccionRh :datos="sesionRh" />
    </div>

    <!-- Colaborador sin contenido visible todavía / ya completado. -->
    <div
        v-else-if="!muestraVisor || !pdf"
        class="pagina-ancha flex flex-col gap-4 pb-10"
    >
        <section
            class="flex flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-6"
        >
            <div class="flex items-start gap-3">
                <CheckCircle2
                    v-if="acceso?.estado === 'completada'"
                    class="size-8 shrink-0 text-success"
                />
                <Clock
                    v-else
                    class="size-8 shrink-0 text-[var(--mrl-primary)]"
                />
                <div class="min-w-0 flex-1">
                    <h1 class="text-lg font-semibold text-[var(--mrl-texto)]">
                        Inducción Mr. Lana
                    </h1>
                    <p class="mt-1 text-sm text-[var(--mrl-texto-suave)]">
                        {{
                            acceso?.mensaje ??
                            'Aún no tienes asignada la Inducción Mr. Lana.'
                        }}
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <Link
                    v-if="acceso?.puede_contestar_evaluacion"
                    :href="evaluacionInduccion.url()"
                    class="inline-flex w-fit items-center gap-2 rounded-xl bg-[var(--mrl-primary)] px-4 py-2.5 text-sm font-medium text-white transition-all hover:gap-3 hover:bg-[var(--mrl-primary-alt)]"
                >
                    Contestar evaluación
                    <ArrowRight class="size-4" />
                </Link>
                <Link
                    :href="miProceso()"
                    class="inline-flex w-fit items-center gap-2 rounded-xl border border-[var(--mrl-borde)] px-4 py-2.5 text-sm font-medium text-[var(--mrl-texto)] transition-colors hover:bg-[var(--mrl-fondo)]"
                >
                    Ir a Mis pendientes
                </Link>
            </div>
        </section>
    </div>

    <!-- Visor: RH (repaso + material) o colaborador (hojas + material). -->
    <div v-else class="pagina-ancha flex flex-col gap-3 pb-10">
        <!-- RH: gestión del material que verá el colaborador. -->
        <section
            v-if="modoPresentacion"
            class="flex flex-col gap-3 rounded-2xl border bg-card p-4"
        >
            <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                Material de explicación de la inducción
            </h2>
            <p v-if="materialRh" class="text-sm text-[var(--mrl-texto-suave)]">
                Activo: <strong>{{ materialRh.tipo_etiqueta }}</strong>
                <template v-if="materialRh.recurso_nombre">
                    · {{ materialRh.recurso_nombre }}
                </template>
                · {{ materialRh.recurso_estado }}
            </p>
            <p v-else class="text-sm text-[var(--mrl-texto-suave)]">
                No hay material cargado: el colaborador solo verá las hojas.
            </p>
            <MaterialInduccionVistaPreviaRh v-if="materialRh" />
            <Link
                :href="vistaPreviaEvaluacion()"
                class="inline-flex w-fit items-center gap-2 text-sm font-medium text-primary underline-offset-4 hover:underline"
            >
                <Eye class="size-4" /> Ver evaluación como colaborador
            </Link>

            <form
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="subirMaterial"
            >
                <label class="flex flex-col gap-1 text-xs">
                    <span>Tipo</span>
                    <select
                        v-model="formMaterial.tipo"
                        class="rounded-lg border px-2 py-1.5 text-sm"
                    >
                        <option value="video">Video (obligatorio)</option>
                        <option value="audio">Audio (narración)</option>
                    </select>
                </label>
                <label class="flex flex-col gap-1 text-xs">
                    <span>Archivo</span>
                    <input
                        type="file"
                        class="text-sm"
                        :accept="
                            formMaterial.tipo === 'video'
                                ? 'video/*'
                                : 'audio/*'
                        "
                        @change="
                            formMaterial.archivo =
                                ($event.target as HTMLInputElement)
                                    .files?.[0] ?? null
                        "
                    />
                </label>
                <button
                    type="submit"
                    class="rounded-lg bg-[var(--mrl-primary)] px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
                    :disabled="!formMaterial.archivo || formMaterial.processing"
                >
                    Subir y activar
                </button>
                <button
                    v-if="materialRh"
                    type="button"
                    class="rounded-lg border px-3 py-1.5 text-sm"
                    @click="retirarMaterial"
                >
                    Retirar
                </button>
            </form>
            <p v-if="formMaterial.errors.archivo" class="text-xs text-danger">
                {{ formMaterial.errors.archivo }}
            </p>
        </section>

        <!-- Hojas del PDF oficial. -->
        <div class="flex h-[70vh] min-h-[28rem] flex-col">
            <VisorPdf
                :url="pdf.url"
                :titulo="pdf.titulo"
                @pagina="registrarPagina"
            />
        </div>

        <!-- Colaborador: material entre las hojas y la evaluación. -->
        <template v-if="!modoPresentacion && material && material.tipo">
            <section
                v-if="esAudio && material.audio_url"
                class="flex flex-col gap-2 rounded-2xl border bg-card p-4"
            >
                <div
                    class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]"
                >
                    <Volume2 class="size-4 text-[var(--mrl-primary)]" />
                    Narración de la inducción
                </div>
                <audio
                    :src="material.audio_url"
                    controls
                    autoplay
                    controlslist="nodownload"
                    class="w-full"
                    @pause="
                        registrarAudio(
                            ($event.target as HTMLAudioElement).currentTime,
                        )
                    "
                    @ended="
                        registrarAudio(
                            ($event.target as HTMLAudioElement).currentTime,
                        )
                    "
                />
            </section>

            <section
                v-else-if="esVideo"
                class="flex flex-col gap-2 rounded-2xl border bg-card p-4"
            >
                <div
                    class="flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]"
                >
                    <VideoIcon class="size-4 text-[var(--mrl-primary)]" />
                    Video de la inducción (obligatorio)
                </div>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Ve el video completo para desbloquear tu evaluación. No se
                    puede adelantar.
                </p>
                <ReproductorVideoInduccion
                    v-if="material.video_disponible && rutas"
                    :iniciar-url="rutas.video_iniciar"
                    :heartbeat-url="rutas.video_heartbeat"
                    :completada-inicial="videoCompletado"
                    @completada="alTerminarVideo"
                />
                <p v-else class="text-sm text-[var(--mrl-texto-suave)]">
                    El video se está procesando. Vuelve en unos minutos.
                </p>
            </section>
        </template>

        <!-- Colaborador: terminar → evaluación. -->
        <div v-if="!modoPresentacion" class="flex justify-end">
            <button
                type="button"
                class="inline-flex items-center gap-2 rounded-xl bg-[var(--mrl-primary)] px-4 py-2.5 text-sm font-medium text-white transition-all hover:gap-3 hover:bg-[var(--mrl-primary-alt)] disabled:opacity-50"
                :disabled="!puedeTerminar"
                @click="terminar"
            >
                Terminé la inducción
                <ArrowRight class="size-4" />
            </button>
        </div>
    </div>
</template>
