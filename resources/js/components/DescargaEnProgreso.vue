<script setup lang="ts">
import {
    AlertTriangle,
    CircleCheck,
    FileArchive,
    RotateCcw,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import type { EstadoDescarga } from '@/composables/useDescargaArchivo';

/**
 * Overlay de «preparando descarga» reutilizable. Se maneja con el estado de
 * useDescargaArchivo(): preparando → lista (se cierra solo) | error.
 * Mientras prepara, «Cancelar» (o Escape) aborta la petición.
 */
const props = withDefaults(
    defineProps<{
        estado: EstadoDescarga;
        error?: string | null;
        titulo?: string;
        /** Persona o recurso que se descarga (se resalta en el subtítulo). */
        sujeto?: string | null;
        descripcion?: string;
        nombreArchivo?: string | null;
    }>(),
    {
        error: null,
        titulo: 'Preparando la descarga',
        sujeto: null,
        descripcion: 'Esto puede tardar unos segundos.',
        nombreArchivo: null,
    },
);

const emit = defineEmits<{
    cancelar: [];
    reintentar: [];
    cerrar: [];
}>();

const abierto = computed(
    () => props.estado !== 'inactiva' && props.estado !== 'cancelada',
);

const tarjeta = ref<HTMLElement | null>(null);
let focoPrevio: HTMLElement | null = null;

const idTitulo = `descarga-titulo-${Math.random().toString(36).slice(2, 9)}`;
const idDescripcion = `${idTitulo}-desc`;

function enfocarPrincipal(): void {
    void nextTick(() => {
        const boton = tarjeta.value?.querySelector<HTMLElement>(
            '[data-foco-inicial]',
        );
        (boton ?? tarjeta.value)?.focus();
    });
}

watch(abierto, (ahora, antes) => {
    if (ahora && !antes) {
        focoPrevio =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        enfocarPrincipal();
    } else if (!ahora && antes) {
        focoPrevio?.focus();
        focoPrevio = null;
    }
});

// Al cambiar de estado, el botón principal cambia: se re-enfoca.
watch(
    () => props.estado,
    () => {
        if (abierto.value) {
            enfocarPrincipal();
        }
    },
);

function alTeclear(evento: KeyboardEvent): void {
    if (evento.key === 'Escape') {
        evento.preventDefault();

        if (props.estado === 'preparando') {
            emit('cancelar');
        } else {
            emit('cerrar');
        }

        return;
    }

    // Trampa de foco simple dentro de la tarjeta.
    if (evento.key === 'Tab' && tarjeta.value) {
        const enfocables = Array.from(
            tarjeta.value.querySelectorAll<HTMLElement>(
                'button:not([disabled]), [href], [tabindex]:not([tabindex="-1"])',
            ),
        );

        if (enfocables.length === 0) {
            evento.preventDefault();

            return;
        }

        const primero = enfocables[0];
        const ultimo = enfocables[enfocables.length - 1];

        if (evento.shiftKey && document.activeElement === primero) {
            evento.preventDefault();
            ultimo.focus();
        } else if (!evento.shiftKey && document.activeElement === ultimo) {
            evento.preventDefault();
            primero.focus();
        }
    }
}

const mensajeVivo = computed(() => {
    switch (props.estado) {
        case 'preparando':
            return `${props.titulo}…`;
        case 'lista':
            return '¡Listo! Tu descarga comenzó.';
        case 'error':
            return props.error ?? 'No se pudo completar la descarga.';
        default:
            return '';
    }
});
</script>

<template>
    <Teleport to="body">
        <Transition name="descarga-fondo">
            <div
                v-if="abierto"
                class="fixed inset-0 z-[100] flex items-center justify-center bg-foreground/25 p-4 backdrop-blur-sm"
                @keydown="alTeclear"
            >
                <Transition name="descarga-tarjeta" appear>
                    <div
                        ref="tarjeta"
                        role="dialog"
                        aria-modal="true"
                        :aria-labelledby="idTitulo"
                        :aria-describedby="idDescripcion"
                        :aria-busy="estado === 'preparando'"
                        tabindex="-1"
                        class="relative w-full max-w-md overflow-hidden rounded-2xl border border-border bg-card p-6 text-center text-card-foreground shadow-xl outline-none sm:p-8"
                    >
                        <!-- Halo decorativo pastel -->
                        <div
                            aria-hidden="true"
                            class="pointer-events-none absolute inset-x-0 -top-24 mx-auto size-56 rounded-full bg-salvia opacity-60 blur-3xl"
                        />

                        <!-- Ícono animado -->
                        <div class="relative mx-auto mb-5 size-20">
                            <template v-if="estado === 'preparando'">
                                <span
                                    aria-hidden="true"
                                    class="descarga-onda absolute inset-0 rounded-full bg-crema"
                                />
                                <span
                                    aria-hidden="true"
                                    class="descarga-onda descarga-onda--tarde absolute inset-0 rounded-full bg-salvia"
                                />
                                <span
                                    class="relative flex size-20 items-center justify-center rounded-full border border-border bg-crema text-bronce shadow-sm"
                                >
                                    <FileArchive
                                        class="descarga-icono size-9"
                                        aria-hidden="true"
                                    />
                                </span>
                            </template>
                            <span
                                v-else-if="estado === 'lista'"
                                class="descarga-exito relative flex size-20 items-center justify-center rounded-full bg-success-soft text-success"
                            >
                                <CircleCheck
                                    class="size-10"
                                    aria-hidden="true"
                                />
                            </span>
                            <span
                                v-else
                                class="relative flex size-20 items-center justify-center rounded-full bg-danger-soft text-danger"
                            >
                                <AlertTriangle
                                    class="size-9"
                                    aria-hidden="true"
                                />
                            </span>
                        </div>

                        <h2
                            :id="idTitulo"
                            class="relative text-lg font-semibold tracking-tight"
                        >
                            <template v-if="estado === 'preparando'">
                                {{ titulo }}
                            </template>
                            <template v-else-if="estado === 'lista'">
                                ¡Listo! Tu descarga comenzó
                            </template>
                            <template v-else>
                                No se pudo completar la descarga
                            </template>
                        </h2>

                        <div
                            :id="idDescripcion"
                            class="relative mt-2 space-y-1 text-sm text-muted-foreground"
                        >
                            <template v-if="estado === 'preparando'">
                                <p
                                    v-if="sujeto"
                                    class="font-medium text-foreground"
                                >
                                    {{ sujeto }}
                                </p>
                                <p>{{ descripcion }}</p>
                            </template>
                            <template v-else-if="estado === 'lista'">
                                <p v-if="nombreArchivo" class="break-all">
                                    {{ nombreArchivo }}
                                </p>
                                <p>Revisa la carpeta de descargas.</p>
                            </template>
                            <p v-else class="text-danger">
                                {{ error ?? 'Ocurrió un error inesperado.' }}
                            </p>
                        </div>

                        <!-- Barra indeterminada -->
                        <div
                            v-if="estado === 'preparando'"
                            class="relative mt-6 h-1.5 w-full overflow-hidden rounded-full bg-crema"
                            role="progressbar"
                            aria-label="Preparando archivo"
                            aria-valuetext="En progreso"
                        >
                            <div
                                class="descarga-barra absolute inset-y-0 w-2/5 rounded-full"
                            />
                        </div>
                        <div
                            v-else-if="estado === 'lista'"
                            class="relative mt-6 h-1.5 w-full overflow-hidden rounded-full bg-success-soft"
                            aria-hidden="true"
                        >
                            <div
                                class="h-full w-full rounded-full bg-success"
                            />
                        </div>

                        <!-- Acciones -->
                        <div
                            class="relative mt-6 flex flex-col-reverse justify-center gap-2 sm:flex-row"
                        >
                            <Button
                                v-if="estado === 'preparando'"
                                variant="outline"
                                size="sm"
                                data-foco-inicial
                                @click="emit('cancelar')"
                            >
                                <X class="size-3.5" aria-hidden="true" />
                                Cancelar
                            </Button>
                            <template v-else-if="estado === 'error'">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    @click="emit('cerrar')"
                                >
                                    Cerrar
                                </Button>
                                <Button
                                    size="sm"
                                    data-foco-inicial
                                    @click="emit('reintentar')"
                                >
                                    <RotateCcw
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Reintentar
                                </Button>
                            </template>
                            <Button
                                v-else
                                variant="ghost"
                                size="sm"
                                data-foco-inicial
                                @click="emit('cerrar')"
                            >
                                Cerrar
                            </Button>
                        </div>

                        <p class="sr-only" aria-live="polite" role="status">
                            {{ mensajeVivo }}
                        </p>
                    </div>
                </Transition>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.descarga-barra {
    background: linear-gradient(
        90deg,
        transparent,
        var(--pastel-oro),
        var(--pastel-esmeralda),
        transparent
    );
    animation: descarga-barra 1.4s ease-in-out infinite;
}

@keyframes descarga-barra {
    0% {
        left: -40%;
    }
    100% {
        left: 100%;
    }
}

.descarga-onda {
    animation: descarga-onda 2.2s ease-out infinite;
}

.descarga-onda--tarde {
    animation-delay: 1.1s;
}

@keyframes descarga-onda {
    0% {
        transform: scale(1);
        opacity: 0.7;
    }
    100% {
        transform: scale(1.65);
        opacity: 0;
    }
}

.descarga-icono {
    animation: descarga-icono 1.8s ease-in-out infinite;
}

@keyframes descarga-icono {
    0%,
    100% {
        transform: translateY(0) rotate(0);
    }
    50% {
        transform: translateY(-3px) rotate(-4deg);
    }
}

.descarga-exito {
    animation: descarga-exito 0.45s cubic-bezier(0.34, 1.56, 0.64, 1);
}

@keyframes descarga-exito {
    0% {
        transform: scale(0.6);
        opacity: 0;
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}

.descarga-fondo-enter-active,
.descarga-fondo-leave-active {
    transition: opacity 0.2s ease;
}

.descarga-fondo-enter-from,
.descarga-fondo-leave-to {
    opacity: 0;
}

.descarga-tarjeta-enter-active {
    transition:
        opacity 0.25s ease,
        transform 0.25s cubic-bezier(0.34, 1.3, 0.64, 1);
}

.descarga-tarjeta-enter-from {
    opacity: 0;
    transform: translateY(8px) scale(0.96);
}

@media (prefers-reduced-motion: reduce) {
    .descarga-onda,
    .descarga-icono,
    .descarga-exito {
        animation: none;
    }

    .descarga-onda {
        opacity: 0;
    }

    /* Barra estática con un pulso de opacidad muy suave en vez de deslizar. */
    .descarga-barra {
        left: 0;
        width: 100%;
        animation: descarga-pulso 2s ease-in-out infinite;
    }

    .descarga-fondo-enter-active,
    .descarga-fondo-leave-active,
    .descarga-tarjeta-enter-active {
        transition: none;
    }
}

@keyframes descarga-pulso {
    0%,
    100% {
        opacity: 0.45;
    }
    50% {
        opacity: 0.8;
    }
}
</style>
