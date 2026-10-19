<script setup lang="ts">
import Hls from 'hls.js';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useAlertas } from '@/composables/useAlertas';
import { postJson } from '@/lib/http';

/**
 * Reproductor del VIDEO obligatorio de la Inducción Mr. Lana. Mismo patrón
 * que MiCapacitacion/ReproductorVideo.vue, pero contra los endpoints de
 * inducción (ReproduccionVideoInduccionService): el anti-adelanto lo respalda
 * el servidor (manifiesto/segmentos truncados al límite visto + @seeking como
 * refuerzo del cliente) y el «completado» es verdad del servidor (≥98%). El
 * progreso vive en el servidor, así que sobrevive a F5.
 */
const props = defineProps<{
    /** URL para arrancar la reproducción (devuelve el manifiesto firmado). */
    iniciarUrl: string;
    /** URL del heartbeat. */
    heartbeatUrl: string;
    /** Si el servidor ya lo tiene por completo (viene de props de la página). */
    completadaInicial?: boolean;
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
let yaCompletada = props.completadaInicial ?? false;
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
            props.heartbeatUrl,
            {
                sesion_id: sesionId,
                posicion_segundos: posicionActual,
            },
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
        // Un heartbeat fallido no interrumpe la reproducción; se reintenta.
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
            props.iniciarUrl,
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

        if (yaCompletada) {
            emit('completada');
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
