<script setup lang="ts">
import Hls from 'hls.js';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useAlertas } from '@/composables/useAlertas';
import { postJson } from '@/lib/http';
import {
    heartbeat,
    iniciar,
} from '@/routes/mi-capacitacion/lecciones/reproduccion';

const props = defineProps<{
    leccionId: number;
}>();

const emit = defineEmits<{
    completada: [];
}>();

type RespuestaIniciar = {
    sesion_id: number;
    posicion_inicial: number;
    duracion_total_segundos: number | null;
    porcentaje_visto: number;
    segundo_maximo_permitido: number;
    heartbeat_segundos: number;
    completada: boolean;
    url_manifiesto: string;
};

type RespuestaHeartbeat = {
    permitido: boolean;
    posicion_permitida: number;
    segundo_maximo_permitido: number;
    porcentaje_visto: number;
    completada: boolean;
};

const { mostrarAdvertencia, mostrarError } = useAlertas();

const elementoVideo = ref<HTMLVideoElement | null>(null);
const cargando = ref(true);
const porcentajeVisto = ref(0);

let reproductorHls: Hls | null = null;
let sesionId: number | null = null;
let ultimaPosicionReportada = 0;
let segundoMaximoPermitido = 0;
let yaCompletada = false;
let intervaloHeartbeat: ReturnType<typeof setInterval> | undefined;

async function enviarHeartbeat() {
    if (!elementoVideo.value || sesionId === null) {
        return;
    }

    const posicionActual = Math.floor(elementoVideo.value.currentTime);

    if (posicionActual === ultimaPosicionReportada) {
        return;
    }

    try {
        const respuesta = await postJson<RespuestaHeartbeat>(
            heartbeat.url(props.leccionId),
            { sesion_id: sesionId, posicion_segundos: posicionActual },
        );

        porcentajeVisto.value = respuesta.porcentaje_visto;
        segundoMaximoPermitido = respuesta.segundo_maximo_permitido;

        if (!respuesta.permitido && elementoVideo.value) {
            elementoVideo.value.currentTime = respuesta.posicion_permitida;
            mostrarAdvertencia(
                'No puedes adelantar el video sin haberlo visto antes.',
            );
        }

        ultimaPosicionReportada = Math.floor(elementoVideo.value.currentTime);

        if (respuesta.completada && !yaCompletada) {
            yaCompletada = true;
            emit('completada');
        }
    } catch {
        // Un heartbeat fallido no interrumpe la reproduccion; se reintenta en el siguiente intervalo.
    }
}

function alValAdelantar() {
    if (!elementoVideo.value) {
        return;
    }

    if (elementoVideo.value.currentTime > segundoMaximoPermitido) {
        elementoVideo.value.currentTime = segundoMaximoPermitido;
    }
}

onMounted(async () => {
    try {
        const respuesta = await postJson<RespuestaIniciar>(
            iniciar.url(props.leccionId),
            {},
        );

        sesionId = respuesta.sesion_id;
        porcentajeVisto.value = respuesta.porcentaje_visto;
        segundoMaximoPermitido = respuesta.segundo_maximo_permitido;
        yaCompletada = respuesta.completada;
        ultimaPosicionReportada = respuesta.posicion_inicial;

        if (elementoVideo.value) {
            if (Hls.isSupported()) {
                reproductorHls = new Hls();
                reproductorHls.loadSource(respuesta.url_manifiesto);
                reproductorHls.attachMedia(elementoVideo.value);
            } else if (
                elementoVideo.value.canPlayType('application/vnd.apple.mpegurl')
            ) {
                elementoVideo.value.src = respuesta.url_manifiesto;
            }

            elementoVideo.value.currentTime = respuesta.posicion_inicial;
        }

        intervaloHeartbeat = setInterval(
            enviarHeartbeat,
            respuesta.heartbeat_segundos * 1000,
        );
    } catch {
        await mostrarError('No fue posible iniciar la reproducción del video.');
    } finally {
        cargando.value = false;
    }
});

onBeforeUnmount(() => {
    if (intervaloHeartbeat) {
        clearInterval(intervaloHeartbeat);
    }

    void enviarHeartbeat();
    reproductorHls?.destroy();
});
</script>

<template>
    <div class="space-y-2">
        <div class="overflow-hidden rounded-lg bg-black">
            <video
                ref="elementoVideo"
                controls
                controlslist="nodownload"
                class="aspect-video w-full"
                @seeking="alValAdelantar"
            />
        </div>
        <p class="text-xs text-muted-foreground">
            {{ cargando ? 'Cargando video…' : `Visto: ${porcentajeVisto}%` }}
        </p>
    </div>
</template>
