<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CircleCheck,
    RefreshCw,
    UserPlus,
    Video as VideoIcon,
    Volume2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import CargaMaterialInduccionRh from '@/components/ciclo/CargaMaterialInduccionRh.vue';
import MaterialInduccionVistaPreviaRh from '@/components/ciclo/MaterialInduccionVistaPreviaRh.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useAccionEnCurso } from '@/composables/useAccionEnCurso';
import { useAlertas } from '@/composables/useAlertas';
import { getJson } from '@/lib/http';
import {
    estado as rutaEstado,
    reprocesar as rutaReprocesar,
} from '@/routes/rh/onboarding/induccion/material';
import type {
    EstadoMaterialInduccionRh,
    LimitesMaterialInduccion,
} from '@/types';

/**
 * Tarjeta «2 · Video o audio de explicación» de la Inducción institucional
 * (RH): estado REAL del material (en cola / procesando / listo / error, con
 * el motivo), sondeo automático mientras el video se procesa, aviso si la
 * cola de video parece detenida, vista previa (el original mientras no hay
 * HLS) y la carga/reemplazo con progreso.
 */
const props = defineProps<{
    material: EstadoMaterialInduccionRh | null;
    puedeConfigurar: boolean;
    rutas: { store: string; destroy: string } | null;
    limites: LimitesMaterialInduccion;
}>();

const emit = defineEmits<{
    asignar: [];
}>();

const accion = useAccionEnCurso();
const alertas = useAlertas();

const estado = ref<EstadoMaterialInduccionRh | null>(props.material);
const subiendo = ref(false);

watch(
    () => props.material,
    (nuevo) => {
        estado.value = nuevo;
    },
);

const esAudio = computed(() => estado.value?.tipo === 'audio');
const enProceso = computed(
    () =>
        estado.value !== null &&
        !estado.value.listo &&
        (estado.value.recurso_estado === 'pendiente' ||
            estado.value.recurso_estado === 'procesando'),
);

const TEXTO_ESTADO: Record<string, string> = {
    disponible: 'Listo para verse',
    procesando: 'Procesando…',
    pendiente: 'En cola para procesarse',
    error: 'Falló el procesamiento',
};

const textoEstado = computed(() =>
    estado.value
        ? (TEXTO_ESTADO[estado.value.recurso_estado] ??
          estado.value.recurso_estado_etiqueta)
        : 'Sin material cargado',
);

function formatearTamano(bytes: number | null): string | null {
    if (!bytes) {
        return null;
    }

    return bytes >= 1_073_741_824
        ? `${(bytes / 1_073_741_824).toFixed(2)} GB`
        : `${(bytes / 1_048_576).toFixed(1)} MB`;
}

// --- Sondeo automático mientras el video está en cola / procesándose ---
const INTERVALO_MS = 5000;
let temporizador: ReturnType<typeof setTimeout> | null = null;

async function consultar() {
    temporizador = null;

    try {
        const respuesta = await getJson<{
            data: EstadoMaterialInduccionRh | null;
        }>(rutaEstado.url());
        const anterior = estado.value;
        estado.value = respuesta.data;

        if (anterior && !anterior.listo && respuesta.data?.listo) {
            toast.success(
                'El video ya quedó listo: el colaborador ya puede verlo.',
            );
        }
    } catch {
        // Un fallo de red puntual no detiene el sondeo.
    }

    programar();
}

function programar() {
    if (temporizador !== null) {
        clearTimeout(temporizador);
        temporizador = null;
    }

    if (enProceso.value) {
        temporizador = setTimeout(consultar, INTERVALO_MS);
    }
}

watch(enProceso, programar, { immediate: true });
onBeforeUnmount(() => {
    if (temporizador !== null) {
        clearTimeout(temporizador);
    }
});

function alSubir() {
    router.reload({ only: ['materialRh'] });
}

async function retirar() {
    const rutas = props.rutas;

    if (
        rutas &&
        (await alertas.confirmarDelicada(
            '¿Retirar el material?',
            'Las personas solo verán las hojas.',
            'Retirar material',
        ))
    ) {
        accion.ejecutar('retirarMaterial', (opciones) => {
            router.delete(rutas.destroy, {
                ...opciones,
                preserveScroll: true,
            });
        });
    }
}

function reprocesar() {
    accion.ejecutar('reprocesarMaterial', (opciones) => {
        router.post(
            rutaReprocesar.url(),
            {},
            { ...opciones, preserveScroll: true },
        );
    });
}
</script>

<template>
    <article
        class="flex flex-col gap-3 rounded-3xl border border-border/60 bg-card p-5 shadow-sm"
    >
        <div class="flex items-center gap-3">
            <span
                class="flex size-10 items-center justify-center rounded-2xl bg-crema text-bronce"
            >
                <Volume2 v-if="esAudio" class="size-5" />
                <VideoIcon v-else class="size-5" />
            </span>
            <div class="min-w-0">
                <h3 class="font-semibold">2 · Video o audio de explicación</h3>
                <p
                    class="flex items-center gap-1.5 truncate text-xs text-muted-foreground"
                >
                    <Spinner v-if="enProceso" class="size-3" />
                    <CircleCheck
                        v-else-if="estado?.listo"
                        class="size-3.5 text-success"
                    />
                    <AlertTriangle
                        v-else-if="estado?.recurso_estado === 'error'"
                        class="size-3.5 text-destructive"
                    />
                    <span class="truncate">
                        <template v-if="estado"
                            >{{ estado.tipo_etiqueta }} · </template
                        >{{ textoEstado }}
                    </span>
                </p>
            </div>
        </div>

        <p class="text-sm text-muted-foreground">
            Un video es obligatorio (sin adelantar) y desbloquea la evaluación;
            un audio acompaña las hojas. Solo uno activo a la vez.
        </p>

        <p
            v-if="estado?.recurso_nombre"
            class="truncate text-xs text-muted-foreground"
        >
            Archivo: {{ estado.recurso_nombre }}
            <template v-if="formatearTamano(estado.tamano_bytes)">
                · {{ formatearTamano(estado.tamano_bytes) }}</template
            >
        </p>

        <!-- Procesando: qué está pasando, en lenguaje llano -->
        <div
            v-if="enProceso && !estado?.cola_detenida"
            class="flex items-start gap-2 rounded-2xl bg-info-soft p-3 text-sm"
            role="status"
            aria-live="polite"
        >
            <Spinner class="mt-0.5 size-4 shrink-0 text-info" />
            <p>
                El servidor está preparando el video para el colaborador (puede
                tardar varios minutos según su duración). Esta tarjeta se
                actualiza sola; mientras tanto ya puedes ver el original.
            </p>
        </div>

        <!-- La cola de video no lo ha tomado / se atoró -->
        <div
            v-if="estado?.cola_detenida"
            class="flex flex-col gap-2 rounded-2xl border border-warning/40 bg-warning-soft p-3 text-sm"
            role="alert"
        >
            <p class="flex items-start gap-2 font-medium">
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
                El video lleva {{ estado.minutos_en_estado }} min «{{
                    textoEstado.toLowerCase()
                }}» sin avanzar.
            </p>
            <p class="text-muted-foreground">
                Es probable que el proceso de video del servidor no esté
                corriendo. Pide a Sistemas revisar el worker de video
                (<code>people-media</code>) o correr
                <code>php artisan people:videos-pendientes --procesar</code>. El
                colaborador no podrá verlo hasta que se procese.
            </p>
        </div>

        <!-- Falló el procesamiento -->
        <div
            v-if="estado?.recurso_estado === 'error'"
            class="flex flex-col gap-1 rounded-2xl border border-destructive/30 bg-danger-soft p-3 text-sm"
            role="alert"
        >
            <p class="font-medium">No se pudo preparar el video.</p>
            <p
                v-if="estado.error"
                class="text-xs break-words text-muted-foreground"
            >
                {{ estado.error }}
            </p>
            <p class="text-xs text-muted-foreground">
                Reintenta el procesamiento o súbelo de nuevo (MP4 es el formato
                más seguro).
            </p>
        </div>

        <Button
            v-if="estado?.puede_reprocesar"
            type="button"
            size="sm"
            variant="outline"
            class="self-start"
            :disabled="accion.ocupado.value || subiendo"
            @click="reprocesar"
        >
            <RefreshCw class="size-4" /> Reintentar procesamiento
        </Button>

        <MaterialInduccionVistaPreviaRh
            v-if="estado"
            :key="estado.recurso_id ?? 'sin-recurso'"
        />

        <CargaMaterialInduccionRh
            v-if="puedeConfigurar && rutas"
            :ruta-audio="rutas.store"
            :reemplazando="estado !== null"
            :limites="limites"
            @ocupado="subiendo = $event"
            @subido="alSubir"
        />

        <div v-if="estado" class="flex flex-wrap gap-2">
            <Button
                variant="ghost"
                size="sm"
                :disabled="subiendo"
                @click="emit('asignar')"
            >
                <UserPlus class="size-4" /> Asignar solo el
                {{ esAudio ? 'audio' : 'video' }}
            </Button>
            <Button
                v-if="puedeConfigurar && rutas"
                type="button"
                size="sm"
                variant="ghost"
                :disabled="accion.ocupado.value || subiendo"
                @click="retirar"
            >
                Retirar
            </Button>
        </div>
    </article>
</template>
