<script setup lang="ts">
import Hls from 'hls.js';
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { useAlertas } from '@/composables/useAlertas';
import { postJson } from '@/lib/http';
import {
    evaluarSeek,
    evaluarTimeUpdate,
} from '@/lib/induccion/antiAdelantoVideo';

/**
 * Reproductor del VIDEO obligatorio de la Inducción Mr. Lana. Mismo patrón
 * que MiCapacitacion/ReproductorVideo.vue, pero contra los endpoints de
 * inducción (ReproduccionVideoInduccionService). El servidor entrega la
 * variante HLS COMPLETA (el video precarga y no se traba) y solo acredita
 * como visto lo que avanza al ritmo del tiempo real (heartbeat). El seek más
 * allá de lo visto lo bloquea este reproductor (seeking + timeupdate, ver
 * lib/induccion/antiAdelantoVideo); la carga o el buffering nunca cuentan como
 * adelanto. El «completado» es verdad del servidor (≥98%) y sobrevive a F5.
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
    /** Lo realmente visto (sin tolerancia). Ausente en servidores viejos. */
    segundo_maximo_visto?: number;
    heartbeat_segundos: number;
    completada: boolean;
    url_manifiesto: string;
};

type RespuestaHeartbeat = {
    permitido: boolean;
    posicion_permitida: number;
    segundo_maximo_permitido: number;
    segundo_maximo_visto: number;
    porcentaje_visto: number;
    completada: boolean;
};

const { mostrarAdvertencia, mostrarError } = useAlertas();

const AVISO_ADELANTO = 'No puedes adelantar el video sin haberlo visto antes.';

const elementoVideo = ref<HTMLVideoElement | null>(null);
const cargando = ref(true);
const porcentajeVisto = ref(0);

let reproductorHls: Hls | null = null;
let sesionId: number | null = null;
let ultimaPosicionReportada = 0;
let yaCompletada = props.completadaInicial ?? false;
let intervaloHeartbeat: ReturnType<typeof setInterval> | undefined;

/**
 * Anti-adelanto del cliente (lib/induccion/antiAdelantoVideo): se valida la
 * POSICIÓN reproducida y los seeks, nunca la carga. `maximoVisto` solo sube
 * con reproducción continua; un seek más allá regresa ahí.
 */
let maximoVisto = 0;
let ultimoTiempo = 0;
let ultimoTiempoReloj = 0;
/** Destino de un regreso que hicimos nosotros (su `seeking` no se evalúa). */
let regresandoA: number | null = null;
let ultimoAviso = 0;

function avisarAdelanto() {
    const ahora = Date.now();

    // Una sola vez por gesto (arrastrar la barra dispara muchos `seeking`).
    if (ahora - ultimoAviso < 4000) {
        return;
    }

    ultimoAviso = ahora;
    void mostrarAdvertencia(AVISO_ADELANTO);
}

function regresarA(segundo: number) {
    if (!elementoVideo.value) {
        return;
    }

    regresandoA = segundo;
    ultimoTiempo = segundo;
    elementoVideo.value.currentTime = segundo;
}

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

        if (respuesta.permitido) {
            maximoVisto = Math.max(maximoVisto, respuesta.segundo_maximo_visto);
        } else if (elementoVideo.value) {
            // El servidor solo rechaza un avance más rápido que el tiempo
            // real (nunca por buffering): es un adelanto de verdad.
            maximoVisto = Math.min(maximoVisto, respuesta.posicion_permitida);
            regresarA(respuesta.posicion_permitida);
            avisarAdelanto();
        }

        if (elementoVideo.value) {
            ultimaPosicionReportada = Math.floor(
                elementoVideo.value.currentTime,
            );
        }

        if (respuesta.completada && !yaCompletada) {
            yaCompletada = true;
            emit('completada');
        }
    } catch {
        // Un heartbeat fallido no interrumpe la reproducción; se reintenta.
    }
}

/** `seeking`: barra, teclado, lo que sea. Se compara ANTES de tocar el máximo. */
function alBuscar() {
    const video = elementoVideo.value;

    if (!video) {
        return;
    }

    const destino = video.currentTime;

    if (regresandoA !== null && Math.abs(destino - regresandoA) < 0.5) {
        regresandoA = null;
        ultimoTiempo = destino;

        return;
    }

    regresandoA = null;
    const resultado = evaluarSeek(destino, maximoVisto);

    if (resultado.regresarA !== null) {
        regresarA(resultado.regresarA);
        avisarAdelanto();

        return;
    }

    ultimoTiempo = destino;
}

/** `timeupdate`: sube el máximo con reproducción continua; atrapa saltos sin `seeking`. */
function alAvanzar() {
    const video = elementoVideo.value;

    if (!video || video.seeking) {
        return;
    }

    const ahora = performance.now();
    const segundosReales =
        ultimoTiempoReloj > 0 ? (ahora - ultimoTiempoReloj) / 1000 : 0;
    ultimoTiempoReloj = ahora;

    const resultado = evaluarTimeUpdate(
        ultimoTiempo,
        video.currentTime,
        maximoVisto,
        segundosReales,
    );
    maximoVisto = resultado.maximoVisto;

    if (resultado.regresarA !== null) {
        regresarA(resultado.regresarA);
        avisarAdelanto();

        return;
    }

    ultimoTiempo = video.currentTime;
}

/** Tras una pausa o un buffering, el reloj real vuelve a contar desde aquí. */
function alReanudar() {
    ultimoTiempoReloj = performance.now();
}

/** Sin acelerar: el servidor no acredita más rápido que el tiempo real. */
function alCambiarVelocidad() {
    if (elementoVideo.value && elementoVideo.value.playbackRate > 1) {
        elementoVideo.value.playbackRate = 1;
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
        yaCompletada = respuesta.completada;
        ultimaPosicionReportada = respuesta.posicion_inicial;
        maximoVisto = Math.max(
            respuesta.posicion_inicial,
            respuesta.segundo_maximo_visto ?? 0,
        );
        ultimoTiempo = respuesta.posicion_inicial;

        if (elementoVideo.value) {
            if (Hls.isSupported()) {
                // VOD completo + buffer amplio: que el video cargue por
                // adelantado y no se trabe (el crédito de «visto» lo cuida
                // el heartbeat, no el buffer).
                reproductorHls = new Hls({
                    startPosition: respuesta.posicion_inicial,
                    maxBufferLength: 120,
                    maxMaxBufferLength: 600,
                    maxBufferSize: 120 * 1000 * 1000,
                });
                reproductorHls.loadSource(respuesta.url_manifiesto);
                reproductorHls.attachMedia(elementoVideo.value);
            } else if (
                elementoVideo.value.canPlayType('application/vnd.apple.mpegurl')
            ) {
                elementoVideo.value.src = respuesta.url_manifiesto;
                regresarA(respuesta.posicion_inicial);
            }
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
                preload="auto"
                @seeking="alBuscar"
                @timeupdate="alAvanzar"
                @playing="alReanudar"
                @ratechange="alCambiarVelocidad"
            />
        </div>
        <p class="text-xs text-muted-foreground">
            {{ cargando ? 'Cargando video…' : `Visto: ${porcentajeVisto}%` }}
        </p>
    </div>
</template>
