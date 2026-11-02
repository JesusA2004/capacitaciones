<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    CircleCheck,
    Pause,
    Play,
    Upload,
    Video as VideoIcon,
    Volume2,
    X,
} from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import {
    leerCargaPendiente,
    limpiarCargaPendiente,
    useCargaResumible,
} from '@/composables/useCargaResumible';
import type { RutasCargaResumible } from '@/composables/useCargaResumible';
import {
    bloque as rutaBloque,
    cancelar as rutaCancelar,
    estado as rutaEstado,
    iniciar as rutaIniciar,
    pausar as rutaPausar,
    reanudar as rutaReanudar,
} from '@/routes/rh/onboarding/induccion/material/cargas';
import type { LimitesMaterialInduccion } from '@/types';

/**
 * Subir / reemplazar el material de explicación de la inducción (RH).
 *  - Video: carga por bloques reanudable (los mismos endpoints que la
 *    biblioteca multimedia, bajo onboarding): progreso real, pausar,
 *    reanudar y cancelar; no choca con el límite de tamaño por request.
 *  - Audio: un solo envío con el progreso de Inertia.
 * Mientras se sube, avisa antes de salir de la página y deja ocupada la
 * tarjeta (`ocupado`) para que no se retire/asigne el material a la mitad.
 */
const props = defineProps<{
    rutaAudio: string;
    reemplazando: boolean;
    limites: LimitesMaterialInduccion;
}>();

const emit = defineEmits<{
    subido: [];
    ocupado: [valor: boolean];
}>();

type TipoMaterial = 'video' | 'audio';

const OPCIONES: {
    valor: TipoMaterial;
    titulo: string;
    detalle: string;
    icono: typeof VideoIcon;
}[] = [
    {
        valor: 'video',
        titulo: 'Video obligatorio',
        detalle: 'Se ve completo, sin adelantar, y desbloquea la evaluación.',
        icono: VideoIcon,
    },
    {
        valor: 'audio',
        titulo: 'Audio de narración',
        detalle: 'Acompaña las hojas; no bloquea la evaluación.',
        icono: Volume2,
    },
];

const ACEPTA: Record<TipoMaterial, string> = {
    video: '.mp4,.m4v,.mov,.webm,.mkv',
    audio: '.mp3,.m4a,.aac,.ogg,.wav,.webm',
};

const CLAVE_LOCAL = 'induccion.material.carga_video_pendiente';

const RUTAS: RutasCargaResumible = {
    iniciar: () => rutaIniciar.url(),
    estado: (id) => rutaEstado.url(id),
    bloque: (id) => rutaBloque.url(id),
    pausar: (id) => rutaPausar.url(id),
    reanudar: (id) => rutaReanudar.url(id),
    cancelar: (id) => rutaCancelar.url(id),
};

const tipo = ref<TipoMaterial>('video');
const archivos = ref<File[]>([]);
const errorVideo = ref<string | null>(null);
const cargaPendiente = ref(leerCargaPendiente(CLAVE_LOCAL));

const carga = useCargaResumible({ rutas: RUTAS, claveLocal: CLAVE_LOCAL });
const estadoVideo = carga.estadoActual;

const formAudio = useForm<{ tipo: 'audio'; archivo: File | null }>({
    tipo: 'audio',
    archivo: null,
});

const archivo = computed(() => archivos.value[0] ?? null);
const maxMb = computed(() =>
    tipo.value === 'video'
        ? props.limites.video_max_mb
        : props.limites.audio_max_mb,
);

const subiendoVideo = computed(() =>
    ['en_progreso', 'ensamblando', 'pausada'].includes(estadoVideo.estado),
);
const subiendo = computed(() => subiendoVideo.value || formAudio.processing);

watch(subiendo, (valor) => emit('ocupado', valor));

// Cambiar de tipo descarta el archivo elegido (no corresponde al otro tipo).
watch(tipo, () => {
    archivos.value = [];
    errorVideo.value = null;
    formAudio.clearErrors();
});

function formatearTamano(bytes: number): string {
    if (bytes >= 1_073_741_824) {
        return `${(bytes / 1_073_741_824).toFixed(2)} GB`;
    }

    return `${(bytes / 1_048_576).toFixed(1)} MB`;
}

const textoProgresoVideo = computed(() => {
    if (estadoVideo.estado === 'ensamblando') {
        return 'Uniendo el archivo en el servidor…';
    }

    const base = `${formatearTamano(estadoVideo.bytesEnviados)} de ${formatearTamano(estadoVideo.tamanoTotalBytes)}`;

    if (estadoVideo.estado === 'pausada') {
        return `En pausa · ${base}`;
    }

    return estadoVideo.velocidadBps > 0
        ? `${base} · ${formatearTamano(estadoVideo.velocidadBps)}/s`
        : base;
});

function mensaje(error: unknown, respaldo: string): string {
    return error instanceof Error ? error.message : respaldo;
}

function alTerminarVideo() {
    if (estadoVideo.estado === 'completada') {
        toast.success(
            'Video subido y activado. Se está procesando; aquí verás cuándo quede listo.',
        );
        cargaPendiente.value = null;
        archivos.value = [];
        carga.reiniciar();
        emit('subido');
    } else if (estadoVideo.estado === 'error') {
        errorVideo.value =
            estadoVideo.error ?? 'No fue posible terminar la carga del video.';
    }
}

async function subirVideo(seleccionado: File) {
    errorVideo.value = null;
    const pendiente = cargaPendiente.value;

    try {
        if (
            pendiente &&
            pendiente.nombreOriginal === seleccionado.name &&
            pendiente.tamanoTotalBytes === seleccionado.size
        ) {
            await carga.reanudarConIdentificador(
                pendiente.identificador,
                seleccionado,
            );
        } else {
            await carga.iniciar(seleccionado, 'video');
        }

        alTerminarVideo();
    } catch (error) {
        errorVideo.value = mensaje(error, 'No fue posible subir el video.');
    }
}

async function continuarVideo() {
    errorVideo.value = null;

    try {
        await carga.reanudar();
        alTerminarVideo();
    } catch (error) {
        errorVideo.value = mensaje(error, 'No fue posible reanudar la carga.');
    }
}

async function cancelarVideo() {
    await carga.cancelar();
    carga.reiniciar();
    cargaPendiente.value = null;
    archivos.value = [];
}

function descartarPendiente() {
    limpiarCargaPendiente(CLAVE_LOCAL);
    cargaPendiente.value = null;
}

function subirAudio(seleccionado: File) {
    formAudio.archivo = seleccionado;
    formAudio.post(props.rutaAudio, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            archivos.value = [];
            formAudio.reset('archivo');
            emit('subido');
        },
    });
}

function subir() {
    if (!archivo.value || subiendo.value) {
        return;
    }

    if (tipo.value === 'video') {
        void subirVideo(archivo.value);
    } else {
        subirAudio(archivo.value);
    }
}

// Salir a la mitad: el navegador pide confirmación; dentro de la app,
// Inertia también. Si sale, la carga del video puede continuarse después
// eligiendo el mismo archivo.
useEventListener(window, 'beforeunload', (evento: BeforeUnloadEvent) => {
    if (subiendo.value) {
        evento.preventDefault();
        evento.returnValue = '';
    }
});

const quitarGuardia = router.on('before', (evento) => {
    // Las visitas que dispara esta misma tarjeta (subir audio) no se frenan.
    if (
        !subiendoVideo.value ||
        evento.detail.visit.url.pathname === window.location.pathname
    ) {
        return;
    }

    if (
        !window.confirm(
            'El video se está subiendo. Si sales, la carga se pausa y podrás continuarla eligiendo el mismo archivo. ¿Salir de todos modos?',
        )
    ) {
        return false;
    }

    carga.pausar();
});

onBeforeUnmount(quitarGuardia);
</script>

<template>
    <div class="flex flex-col gap-3 rounded-2xl bg-muted/40 p-3">
        <p class="text-xs font-medium">
            {{ reemplazando ? 'Reemplazar por' : 'Subir' }}
        </p>

        <div
            role="radiogroup"
            aria-label="Tipo de material"
            class="grid gap-2 sm:grid-cols-2"
        >
            <button
                v-for="opcion in OPCIONES"
                :key="opcion.valor"
                type="button"
                role="radio"
                :aria-checked="tipo === opcion.valor"
                :disabled="subiendo"
                class="flex items-start gap-3 rounded-2xl border p-3 text-left transicion-ui focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-60"
                :class="
                    tipo === opcion.valor
                        ? 'border-primary/60 bg-card shadow-sm'
                        : 'border-border/60 bg-card/60 hover:border-primary/30 hover:bg-card'
                "
                @click="tipo = opcion.valor"
            >
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                    :class="
                        tipo === opcion.valor
                            ? 'bg-salvia text-primary'
                            : 'bg-crema text-bronce'
                    "
                >
                    <component :is="opcion.icono" class="size-4" />
                </span>
                <span class="min-w-0 flex-1">
                    <span
                        class="flex items-center gap-1.5 text-sm font-semibold"
                    >
                        {{ opcion.titulo }}
                        <CircleCheck
                            v-if="tipo === opcion.valor"
                            class="size-4 text-primary"
                        />
                    </span>
                    <span class="mt-0.5 block text-xs text-muted-foreground">
                        {{ opcion.detalle }}
                    </span>
                </span>
            </button>
        </div>

        <div
            v-if="tipo === 'video' && cargaPendiente && !subiendoVideo"
            class="flex flex-col gap-2 rounded-2xl border border-dashed border-warning/50 bg-warning-soft/50 p-3 text-sm"
        >
            <p>
                Quedó una carga sin terminar:
                <strong>{{ cargaPendiente.nombreOriginal }}</strong>
                ({{ formatearTamano(cargaPendiente.tamanoTotalBytes) }}). Elige
                el mismo archivo para continuarla desde donde se quedó.
            </p>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="self-start"
                @click="descartarPendiente"
            >
                Descartar y empezar de nuevo
            </Button>
        </div>

        <PeopleFileDropzone
            v-if="!subiendoVideo"
            v-model="archivos"
            :accept="ACEPTA[tipo]"
            :max-size-mb="maxMb"
            :label="
                tipo === 'video'
                    ? 'Arrastra el video aquí'
                    : 'Arrastra el audio aquí'
            "
            :loading="formAudio.processing"
            :progreso="formAudio.progress?.percentage ?? null"
            :error="tipo === 'audio' ? formAudio.errors.archivo : errorVideo"
            vista-previa
        />

        <!-- Progreso real del video por bloques -->
        <div
            v-else
            class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-3"
            role="status"
            aria-live="polite"
        >
            <div class="flex items-center justify-between gap-2 text-sm">
                <span class="min-w-0 truncate font-medium">
                    {{ archivo?.name ?? 'Video' }}
                </span>
                <span class="font-semibold text-primary tabular-nums">
                    {{ estadoVideo.porcentaje }}%
                </span>
            </div>
            <Progress :model-value="estadoVideo.porcentaje" class="h-2" />
            <p class="text-xs text-muted-foreground tabular-nums">
                {{ textoProgresoVideo }}
            </p>
            <p v-if="errorVideo" class="text-sm text-destructive" role="alert">
                {{ errorVideo }}
            </p>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="estadoVideo.estado === 'en_progreso'"
                    type="button"
                    size="sm"
                    variant="outline"
                    @click="carga.pausar()"
                >
                    <Pause class="size-4" /> Pausar
                </Button>
                <Button
                    v-else-if="estadoVideo.estado === 'pausada'"
                    type="button"
                    size="sm"
                    @click="continuarVideo"
                >
                    <Play class="size-4" /> Reanudar
                </Button>
                <Button
                    v-if="estadoVideo.estado !== 'ensamblando'"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="text-destructive hover:text-destructive"
                    @click="cancelarVideo"
                >
                    <X class="size-4" /> Cancelar
                </Button>
            </div>
            <p class="text-xs text-muted-foreground">
                No cierres esta pestaña mientras se sube. Si se interrumpe,
                vuelve a elegir el mismo archivo y continúa desde donde se
                quedó.
            </p>
        </div>

        <Button
            v-if="!subiendoVideo"
            type="button"
            size="sm"
            class="self-start"
            :disabled="!archivo || subiendo"
            @click="subir"
        >
            <Upload class="size-4" />
            {{
                formAudio.processing
                    ? `Subiendo… ${formAudio.progress?.percentage ?? 0}%`
                    : 'Subir y activar'
            }}
        </Button>
    </div>
</template>
