<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Eye,
    Lock,
    Presentation,
    Video as VideoIcon,
    Volume2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import MaterialInduccionVistaPreviaRh from '@/components/ciclo/MaterialInduccionVistaPreviaRh.vue';
import ReproductorVideoInduccion from '@/components/ciclo/ReproductorVideoInduccion.vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import MiInduccionTarjeta from '@/components/onboarding/MiInduccionTarjeta.vue';
import { postJson } from '@/lib/http';
import type { MaterialInduccion, MiInduccion } from '@/types';

/**
 * Lo que el colaborador ve al entrar a su inducción: las hojas del PDF
 * oficial, el material de explicación (video obligatorio o audio) y
 * «Terminé la inducción» → evaluación. Solo lo que RH le asignó
 * (presentación y/o material). La MISMA vista en Mi espacio y en «Ver como
 * colaborador» de RH: con `vistaPrevia` no se reporta ninguna página, el
 * video se ve con el reproductor de vista previa de RH (sin anti-adelanto ni
 * avance) y no se puede terminar.
 */
const props = withDefaults(
    defineProps<{
        pdf: { url: string; titulo: string; total_paginas: number };
        induccion: MiInduccion;
        material?: MaterialInduccion | null;
        videoCompletado?: boolean;
        rutas?: {
            pagina: string;
            terminar: string;
            video_iniciar: string;
            video_heartbeat: string;
            audio_progreso: string;
        } | null;
        vistaPrevia?: boolean;
    }>(),
    { material: null, videoCompletado: false, rutas: null, vistaPrevia: false },
);

const incluyePresentacion = computed(
    () => props.induccion.presentacion.incluida,
);
const materialAsignado = computed(
    () =>
        props.induccion.material !== null && props.induccion.material.incluido,
);
const tipoMaterial = computed(
    () => props.material?.tipo ?? props.induccion.material?.tipo ?? null,
);
const esVideo = computed(() => tipoMaterial.value === 'video');

const paginasVistas = ref(props.induccion.presentacion.paginas_vistas);
const videoListo = ref(props.videoCompletado);
const total = computed(
    () => props.pdf.total_paginas || props.induccion.presentacion.total_paginas,
);
const porcentajeHojas = computed(() =>
    total.value > 0
        ? Math.min(100, Math.floor((paginasVistas.value / total.value) * 100))
        : 0,
);
const vistas = new Set<number>();

const faltaVideo = computed(
    () => materialAsignado.value && esVideo.value && !videoListo.value,
);
// «ver todas las hojas y ver el video completo» — sin espacios sueltos.
const requisitos = computed(() =>
    [
        incluyePresentacion.value ? 'ver todas las hojas' : null,
        materialAsignado.value && esVideo.value
            ? 'ver el video completo'
            : null,
    ]
        .filter((r): r is string => r !== null)
        .join(' y '),
);
const puedeTerminar = computed(() => !props.vistaPrevia && !faltaVideo.value);

function registrarPagina(numero: number) {
    if (props.vistaPrevia || !props.rutas) {
        return;
    }

    if (!vistas.has(numero)) {
        vistas.add(numero);
        paginasVistas.value = Math.max(paginasVistas.value, vistas.size);
    }

    void postJson<{ data: { paginas_vistas: number[] } | null }>(
        props.rutas.pagina,
        {
            pagina: numero,
        },
    )
        .then((r) => {
            if (r.data) {
                paginasVistas.value = r.data.paginas_vistas.length;
            }
        })
        .catch(() => {});
}

function registrarAudio(segundos: number) {
    if (!props.vistaPrevia && props.rutas) {
        void postJson(props.rutas.audio_progreso, {
            posicion_segundos: Math.floor(segundos),
        }).catch(() => {});
    }
}

function terminar() {
    if (props.rutas && puedeTerminar.value) {
        router.post(props.rutas.terminar);
    }
}
</script>

<template>
    <!-- Angosto (< xl): avance arriba, contenido y «¿Terminaste?» al final.
         Ancho: contenido a la izquierda y el lateral (avance + terminar)
         fijo a la derecha. -->
    <div
        class="grid items-start gap-4 lg:gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:grid-rows-[auto_1fr] 2xl:grid-cols-[minmax(0,1fr)_26rem]"
    >
        <div class="order-1 xl:order-none xl:col-start-2 xl:row-start-1">
            <MiInduccionTarjeta :induccion="induccion" sin-accion compacto />
        </div>

        <div
            class="order-2 flex min-w-0 flex-col gap-4 xl:order-none xl:col-start-1 xl:row-span-2 xl:row-start-1"
        >
            <!-- Presentación: hojas del PDF oficial -->
            <section
                v-if="incluyePresentacion"
                class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-3 shadow-sm sm:p-4"
            >
                <div class="flex flex-wrap items-center gap-3 px-1">
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-salvia text-primary"
                    >
                        <Presentation class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold">
                            Paso 1 · Presentación
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Avanza hoja por hoja: cada hoja que veas se guarda
                            sola.
                        </p>
                    </div>
                    <span class="text-sm font-semibold tabular-nums"
                        >{{ paginasVistas }} / {{ total }} hojas</span
                    >
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        class="h-full rounded-full bg-primary transition-all"
                        :style="{ width: `${porcentajeHojas}%` }"
                    />
                </div>
                <!-- Alto proporcional a una hoja horizontal (16:9) + barras del
                     visor: sin un hueco enorme en móvil ni hojas diminutas
                     en monitores grandes. -->
                <div
                    class="flex h-[clamp(22rem,calc(56.25vw+8rem),80vh)] flex-col md:h-[clamp(24rem,calc(56.25vw-2rem),80vh)] xl:h-[clamp(28rem,calc(56.25vw-16rem),80vh)]"
                >
                    <VisorPdf
                        :url="pdf.url"
                        :titulo="pdf.titulo"
                        @pagina="registrarPagina"
                    />
                </div>
            </section>

            <!-- Material de explicación -->
            <section
                v-if="materialAsignado"
                class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="flex size-9 items-center justify-center rounded-xl bg-crema text-bronce"
                    >
                        <VideoIcon v-if="esVideo" class="size-5" />
                        <Volume2 v-else class="size-5" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <h2 class="text-sm font-semibold">
                            Paso {{ incluyePresentacion ? 2 : 1 }} ·
                            {{
                                esVideo
                                    ? 'Video de la inducción'
                                    : 'Narración de la inducción'
                            }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{
                                esVideo
                                    ? 'Velo completo para desbloquear tu evaluación. No se puede adelantar.'
                                    : 'Escúchala mientras revisas las hojas.'
                            }}
                        </p>
                    </div>
                    <span
                        v-if="esVideo && videoListo"
                        class="flex items-center gap-1 text-sm font-medium text-success"
                        ><CheckCircle2 class="size-4" /> Visto</span
                    >
                </div>

                <MaterialInduccionVistaPreviaRh v-if="vistaPrevia" />
                <template v-else-if="material && material.tipo">
                    <audio
                        v-if="!esVideo && material.audio_url"
                        :src="material.audio_url"
                        controls
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
                    <ReproductorVideoInduccion
                        v-else-if="
                            esVideo && material.video_disponible && rutas
                        "
                        :iniciar-url="rutas.video_iniciar"
                        :heartbeat-url="rutas.video_heartbeat"
                        :completada-inicial="videoCompletado"
                        @completada="videoListo = true"
                    />
                    <p v-else class="text-sm text-muted-foreground">
                        El material se está procesando. Vuelve en unos minutos.
                    </p>
                </template>
            </section>
        </div>

        <!-- Terminar -->
        <aside
            class="order-3 flex flex-col gap-4 xl:sticky xl:top-4 xl:order-none xl:col-start-2 xl:row-start-2"
        >
            <section
                class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            >
                <h2 class="text-sm font-semibold">¿Terminaste?</h2>
                <p class="text-sm text-muted-foreground">
                    Al terminar se desbloquea tu evaluación.
                    <template v-if="requisitos"
                        >Necesitas {{ requisitos }}.</template
                    >
                </p>
                <p
                    v-if="faltaVideo && !vistaPrevia"
                    class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
                >
                    <Lock class="size-3.5 shrink-0" /> Te falta ver el video
                    completo.
                </p>
                <p
                    v-if="vistaPrevia"
                    class="flex items-center gap-2 rounded-xl bg-warning-soft px-3 py-2 text-xs"
                >
                    <Eye class="size-3.5 shrink-0" /> Vista previa: aquí el
                    colaborador presiona «Terminé la inducción». No se registra
                    nada.
                </p>
                <button
                    type="button"
                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-semibold text-primary-foreground transition-all hover:gap-3 hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50 sm:self-start sm:px-6 xl:self-stretch"
                    :disabled="!puedeTerminar"
                    @click="terminar"
                >
                    Terminé la inducción <ArrowRight class="size-4" />
                </button>
            </section>
        </aside>
    </div>
</template>
