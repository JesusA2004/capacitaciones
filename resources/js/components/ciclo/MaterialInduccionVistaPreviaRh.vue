<script setup lang="ts">
import { Eye, Info, Volume2, Video as VideoIcon } from '@lucide/vue';
import Hls from 'hls.js';
import { nextTick, onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { getJson } from '@/lib/http';
import { datos as datosVistaPrevia } from '@/routes/rh/onboarding/induccion/material/vista-previa';

/**
 * RH escucha/ve el material de explicación de la inducción tal cual lo
 * recibirá el colaborador. RH no está sujeto al anti-adelanto ni registra
 * avance: los endpoints de vista previa sirven el video HLS completo y la
 * narración original (MaterialInduccionService::vistaPreviaRh). Las URLs
 * firmadas se piden al reproducir, para que no caduquen con la página.
 *
 * Mientras el HLS del video no está listo (en cola, procesando o con error),
 * RH puede ver el ARCHIVO ORIGINAL que subió (`original_url`, solo RH). El
 * colaborador siempre recibe el HLS con anti-adelanto.
 */
type DatosVistaPrevia = {
    tipo: string;
    tipo_etiqueta: string;
    disponible: boolean;
    audio_url: string | null;
    video_url: string | null;
    original_url: string | null;
    estado: string;
    error: string | null;
};

const cargando = ref(false);
const error = ref<string | null>(null);
const datos = ref<DatosVistaPrevia | null>(null);
const elementoVideo = ref<HTMLVideoElement | null>(null);
let reproductorHls: Hls | null = null;

async function abrir() {
    cargando.value = true;
    error.value = null;

    try {
        const respuesta = await getJson<{ data: DatosVistaPrevia | null }>(
            datosVistaPrevia.url(),
        );
        datos.value = respuesta.data;

        if (!respuesta.data) {
            error.value = 'No hay material de explicación activo.';
        } else if (respuesta.data.video_url) {
            await nextTick();
            montarVideo(respuesta.data.video_url);
        } else if (!respuesta.data.disponible && !respuesta.data.original_url) {
            error.value =
                respuesta.data.estado === 'error'
                    ? 'El video no se pudo procesar y su archivo original no está disponible. Vuelve a subirlo.'
                    : 'El material todavía no está disponible. Vuelve en unos minutos.';
        }
    } catch {
        error.value = 'No fue posible cargar el material.';
    } finally {
        cargando.value = false;
    }
}

function montarVideo(url: string) {
    if (!elementoVideo.value) {
        return;
    }

    reproductorHls?.destroy();
    reproductorHls = null;

    if (Hls.isSupported()) {
        reproductorHls = new Hls();
        reproductorHls.loadSource(url);
        reproductorHls.attachMedia(elementoVideo.value);
    } else if (
        elementoVideo.value.canPlayType('application/vnd.apple.mpegurl')
    ) {
        elementoVideo.value.src = url;
    }
}

function alFallarOriginal() {
    error.value =
        'Tu navegador no puede reproducir este formato de video directamente. Podrás verlo en cuanto termine el procesamiento.';
}

function cerrar() {
    reproductorHls?.destroy();
    reproductorHls = null;
    datos.value = null;
    error.value = null;
}

onBeforeUnmount(() => reproductorHls?.destroy());
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-if="!datos"
                type="button"
                variant="outline"
                size="sm"
                :disabled="cargando"
                @click="abrir"
            >
                <Eye class="size-4" />
                {{ cargando ? 'Cargando…' : 'Escuchar / ver el material' }}
            </Button>
            <Button
                v-else
                type="button"
                variant="ghost"
                size="sm"
                @click="cerrar"
                >Cerrar reproductor</Button
            >
        </div>

        <p v-if="error" class="text-sm text-muted-foreground">{{ error }}</p>

        <div
            v-if="datos && (datos.disponible || datos.original_url)"
            class="flex flex-col gap-2 rounded-2xl border bg-warning-soft/40 p-3"
        >
            <p
                v-if="datos.disponible"
                class="flex items-center gap-2 text-xs font-medium"
            >
                <Eye class="size-3.5" /> Vista previa — así lo verá el
                colaborador. Para ti no hay anti-adelanto y no se registra
                avance.
            </p>
            <p v-else class="flex items-start gap-2 text-xs font-medium">
                <Info class="mt-0.5 size-3.5 shrink-0" /> Estás viendo el
                archivo original que subiste. El colaborador lo verá (sin poder
                adelantar) en cuanto termine el procesamiento.
            </p>
            <div
                v-if="datos.audio_url"
                class="flex items-center gap-2 text-sm font-semibold"
            >
                <Volume2 class="size-4 text-primary" /> Narración de la
                inducción
            </div>
            <audio
                v-if="datos.audio_url"
                :src="datos.audio_url"
                controls
                controlslist="nodownload"
                class="w-full"
            />
            <template v-if="datos.video_url || datos.original_url">
                <div class="flex items-center gap-2 text-sm font-semibold">
                    <VideoIcon class="size-4 text-primary" /> Video de la
                    inducción (obligatorio para el colaborador)
                </div>
                <div class="overflow-hidden rounded-lg bg-foreground">
                    <video
                        v-if="datos.video_url"
                        ref="elementoVideo"
                        controls
                        controlslist="nodownload"
                        class="aspect-video w-full"
                    />
                    <video
                        v-else-if="datos.original_url"
                        :src="datos.original_url"
                        controls
                        controlslist="nodownload"
                        preload="metadata"
                        class="aspect-video w-full"
                        @error="alFallarOriginal"
                    />
                </div>
            </template>
        </div>
    </div>
</template>
