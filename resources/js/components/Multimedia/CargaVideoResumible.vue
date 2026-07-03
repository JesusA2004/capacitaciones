<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    leerCargaPendiente,
    limpiarCargaPendiente,
    useCargaResumible,
} from '@/composables/useCargaResumible';

const emit = defineEmits<{
    completado: [recursoId: number];
}>();

const {
    estadoActual,
    iniciar,
    reanudarConIdentificador,
    pausar,
    reanudar,
    cancelar,
    reiniciar,
} = useCargaResumible();

const inputArchivo = ref<HTMLInputElement | null>(null);
const archivoSeleccionado = ref<File | null>(null);
const mensajeError = ref<string | null>(null);

type CargaPendienteVisible = {
    identificador: string;
    nombreOriginal: string;
    tamanoTotalBytes: number;
};

const cargaPendiente = ref<CargaPendienteVisible | null>(leerCargaPendiente());

function formatearTamano(bytes: number): string {
    if (bytes >= 1_073_741_824) {
        return `${(bytes / 1_073_741_824).toFixed(2)} GB`;
    }

    if (bytes >= 1_048_576) {
        return `${(bytes / 1_048_576).toFixed(1)} MB`;
    }

    return `${(bytes / 1024).toFixed(0)} KB`;
}

function formatearVelocidad(bytesPorSegundo: number): string {
    return `${formatearTamano(bytesPorSegundo)}/s`;
}

async function seleccionarArchivo(evento: Event) {
    const archivo = (evento.target as HTMLInputElement).files?.[0] ?? null;
    archivoSeleccionado.value = archivo;
    mensajeError.value = null;

    if (!archivo) {
        return;
    }

    // Si el archivo seleccionado coincide (mismo nombre y tamaño) con una
    // carga incompleta previa, se reanuda en vez de empezar de cero.
    if (
        cargaPendiente.value &&
        cargaPendiente.value.nombreOriginal === archivo.name &&
        cargaPendiente.value.tamanoTotalBytes === archivo.size
    ) {
        try {
            await reanudarConIdentificador(
                cargaPendiente.value.identificador,
                archivo,
            );

            if (
                estadoActual.estado === 'completada' &&
                estadoActual.recursoMultimediaId
            ) {
                emit('completado', estadoActual.recursoMultimediaId);
            }
        } catch (error) {
            mensajeError.value =
                error instanceof Error
                    ? error.message
                    : 'No fue posible reanudar la carga.';
        }

        return;
    }

    try {
        await iniciar(archivo, 'video');

        if (
            estadoActual.estado === 'completada' &&
            estadoActual.recursoMultimediaId
        ) {
            emit('completado', estadoActual.recursoMultimediaId);
        }
    } catch (error) {
        mensajeError.value =
            error instanceof Error
                ? error.message
                : 'No fue posible iniciar la carga.';
    }
}

async function continuar() {
    mensajeError.value = null;

    try {
        await reanudar();

        if (
            estadoActual.estado === 'completada' &&
            estadoActual.recursoMultimediaId
        ) {
            emit('completado', estadoActual.recursoMultimediaId);
        }
    } catch (error) {
        mensajeError.value =
            error instanceof Error
                ? error.message
                : 'No fue posible reanudar la carga.';
    }
}

async function cancelarCarga() {
    await cancelar();
    archivoSeleccionado.value = null;
    cargaPendiente.value = null;

    if (inputArchivo.value) {
        inputArchivo.value.value = '';
    }

    reiniciar();
}

function descartarCargaPendiente() {
    limpiarCargaPendiente();
    cargaPendiente.value = null;
}

onMounted(() => {
    cargaPendiente.value = leerCargaPendiente();
});

defineExpose({ estadoActual });
</script>

<template>
    <div class="flex flex-col gap-3">
        <div
            v-if="cargaPendiente && estadoActual.estado === 'inactivo'"
            class="rounded-md border border-dashed p-3 text-sm"
        >
            <p>
                Tienes una carga incompleta:
                <strong>{{ cargaPendiente.nombreOriginal }}</strong>
                ({{ formatearTamano(cargaPendiente.tamanoTotalBytes) }}).
            </p>
            <p class="mt-1 text-xs text-muted-foreground">
                Selecciona el mismo archivo para continuarla, o descártala para
                empezar una carga nueva.
            </p>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="mt-2"
                @click="descartarCargaPendiente"
            >
                Descartar carga pendiente
            </Button>
        </div>

        <input
            id="archivo-video"
            ref="inputArchivo"
            type="file"
            accept="video/*"
            class="text-sm file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-primary-foreground"
            :disabled="
                estadoActual.estado === 'en_progreso' ||
                estadoActual.estado === 'ensamblando'
            "
            @change="seleccionarArchivo"
        />

        <div
            v-if="estadoActual.estado !== 'inactivo'"
            class="flex flex-col gap-2"
        >
            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                <div
                    class="h-full bg-[var(--brand-primary)] transition-all"
                    :style="{ width: `${estadoActual.porcentaje}%` }"
                />
            </div>

            <div
                class="flex items-center justify-between text-xs text-muted-foreground"
            >
                <span>
                    {{ formatearTamano(estadoActual.bytesEnviados) }} /
                    {{ formatearTamano(estadoActual.tamanoTotalBytes) }}
                    ({{ estadoActual.porcentaje }}%)
                </span>
                <span v-if="estadoActual.estado === 'en_progreso'">
                    {{ formatearVelocidad(estadoActual.velocidadBps) }}
                </span>
                <span class="font-medium capitalize">{{
                    estadoActual.estado.replace('_', ' ')
                }}</span>
            </div>

            <div class="flex gap-2">
                <Button
                    v-if="estadoActual.estado === 'en_progreso'"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="pausar"
                >
                    Pausar
                </Button>
                <Button
                    v-if="estadoActual.estado === 'pausada'"
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="continuar"
                >
                    Continuar
                </Button>
                <Button
                    v-if="
                        !['completada', 'cancelada', 'inactivo'].includes(
                            estadoActual.estado,
                        )
                    "
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="cancelarCarga"
                >
                    Cancelar
                </Button>
            </div>
        </div>

        <p v-if="mensajeError" class="text-sm text-destructive">
            {{ mensajeError }}
        </p>
        <p
            v-if="estadoActual.estado === 'error' && estadoActual.error"
            class="text-sm text-destructive"
        >
            {{ estadoActual.error }}
        </p>
        <p
            v-if="estadoActual.estado === 'completada'"
            class="text-sm text-[var(--success)]"
        >
            Carga completada. El video se está procesando en segundo plano.
        </p>
    </div>
</template>
