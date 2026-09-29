<script setup lang="ts">
import { Compass, Lightbulb, Loader2, X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    buscarElementoVisible,
    useTourGuiado,
} from '@/composables/useTourGuiado';
import { calcularPosicion, rectSpotlight } from '@/lib/tours/motor';

/**
 * Overlay de "spotlight" para el tour guiado. Se monta UNA sola vez en
 * `AppSidebarLayout.vue`; el estado y la navegación entre pantallas viven
 * en `useTourGuiado()`, este componente solo dibuja.
 *
 * Recalcula en cada frame la posición del elemento resaltado (en vez de
 * listeners de scroll/resize) para seguir cualquier animación, scroll o
 * cambio de layout, y mide el alto REAL del tooltip para colocarlo abajo,
 * arriba, a un lado o fijo abajo de la pantalla según el espacio que haya.
 */
const {
    tourActivo,
    paso,
    pasoActual,
    totalPasos,
    esUltimoPaso,
    esPrimerPaso,
    estado,
    cargando,
    elemento,
    secciones,
    haySiguienteSeccion,
    siguiente,
    anterior,
    saltarPaso,
    saltarSeccion,
    finalizar,
} = useTourGuiado();

const rect = ref<DOMRect | null>(null);
const viewport = ref({ ancho: 0, alto: 0 });
const tooltip = ref<HTMLElement | null>(null);
const tamanoTooltip = ref({ ancho: 0, alto: 0 });
let rafId: number | null = null;

function objetivoActual(): HTMLElement | null {
    const actual = elemento.value;

    if (actual?.isConnected) {
        return actual;
    }

    // Inertia puede re-renderizar la pantalla (recarga parcial, filtros) y
    // reemplazar el nodo: se vuelve a buscar por selector.
    return paso.value?.selector
        ? buscarElementoVisible(paso.value.selector)
        : null;
}

/**
 * Cada cuadro: rect del objetivo, tamaño REAL de la caja y de la pantalla.
 * Cubre scroll, redimensionar la ventana, abrir/cerrar el sidebar, zoom y
 * animaciones sin listeners sueltos.
 */
function actualizar(): void {
    viewport.value = { ancho: window.innerWidth, alto: window.innerHeight };

    const objetivo = estado.value === 'showing' ? objetivoActual() : null;
    rect.value = objetivo ? objetivo.getBoundingClientRect() : null;
    tamanoTooltip.value = {
        ancho: tooltip.value?.offsetWidth ?? 0,
        alto: tooltip.value?.offsetHeight ?? 0,
    };

    rafId = requestAnimationFrame(actualizar);
}

function detenerSeguimiento(): void {
    if (rafId !== null) {
        cancelAnimationFrame(rafId);
    }

    rafId = null;
}

watch(
    tourActivo,
    (activo) => {
        detenerSeguimiento();

        if (activo) {
            rafId = requestAnimationFrame(actualizar);
        } else {
            rect.value = null;
        }
    },
    { immediate: true },
);

onBeforeUnmount(detenerSeguimiento);

const esCentrado = computed(
    () => estado.value === 'showing' && rect.value === null,
);

/**
 * Ancho de la caja. Si en un paso la regla de posición tuvo que angostarla
 * para no tapar el objetivo, ese ancho se FIJA durante el paso: el alto
 * medido depende del ancho y el ancho de la decisión por alto, así que sin
 * fijarlo la caja oscilaba entre dos anchos cada cuadro (y sus botones no
 * se podían presionar). Se libera al cambiar de paso o de tamaño de pantalla.
 */
const anchoFijado = ref<number | null>(null);
const anchoPreferido = computed(
    () => anchoFijado.value ?? (esCentrado.value ? 460 : 380),
);

// Getters primitivos: `viewport` se reemplaza cada cuadro, y un getter que
// regresara un arreglo dispararía (y liberaría el ancho) en cada cuadro.
const liberarAncho = (): void => {
    anchoFijado.value = null;
};
watch(pasoActual, liberarAncho);
watch(() => viewport.value.ancho, liberarAncho);
watch(() => viewport.value.alto, liberarAncho);

/**
 * Posición calculada por lib/tours/motor.ts (calcularPosicion): nunca sobre
 * el elemento resaltado si hay forma de evitarlo; en móvil, hoja inferior
 * (o superior si el elemento quedaría debajo).
 */
const posicion = computed(() =>
    calcularPosicion(
        rect.value
            ? {
                  top: rect.value.top,
                  left: rect.value.left,
                  width: rect.value.width,
                  height: rect.value.height,
              }
            : null,
        {
            ancho: anchoPreferido.value,
            // Antes de medir por primera vez se usa un alto razonable.
            alto: tamanoTooltip.value.alto || 240,
        },
        viewport.value,
    ),
);

watch(posicion, (actual) => {
    const esHoja =
        actual.modo === 'hoja-abajo' || actual.modo === 'hoja-arriba';

    if (
        anchoFijado.value === null &&
        !esHoja &&
        actual.ancho < anchoPreferido.value
    ) {
        anchoFijado.value = actual.ancho;
    }
});

const estiloTooltip = computed(() => ({
    width: `${posicion.value.ancho}px`,
    left: `${posicion.value.left}px`,
    top: `${posicion.value.top}px`,
}));

const estiloSpotlight = computed(() => {
    if (!rect.value) {
        return { display: 'none' };
    }

    const hueco = rectSpotlight({
        top: rect.value.top,
        left: rect.value.left,
        width: rect.value.width,
        height: rect.value.height,
    });

    return {
        top: `${hueco.top}px`,
        left: `${hueco.left}px`,
        width: `${hueco.width}px`,
        height: `${hueco.height}px`,
    };
});

const porcentaje = computed(() =>
    totalPasos.value
        ? Math.round(((pasoActual.value + 1) / totalPasos.value) * 100)
        : 0,
);

/** Solo en recorridos de varios módulos tiene sentido "saltar módulo". */
const mostrarSaltar = computed(
    () => secciones.value.length > 2 && haySiguienteSeccion.value,
);

const indiceSeccion = computed(() => {
    const seccion = paso.value?.seccion;

    return seccion ? secciones.value.indexOf(seccion) : -1;
});

function alTeclado(evento: KeyboardEvent): void {
    if (!tourActivo.value) {
        return;
    }

    if (evento.key === 'Escape') {
        evento.preventDefault();
        finalizar();

        return;
    }

    // Enter sobre un botón ya lo "clickea": no avanzar dos veces.
    const objetivo = evento.target as HTMLElement | null;
    const esControl = objetivo?.closest('button, a, input, textarea, select');

    if (evento.key === 'ArrowRight' || (evento.key === 'Enter' && !esControl)) {
        evento.preventDefault();
        siguiente();
    }

    if (evento.key === 'ArrowLeft') {
        evento.preventDefault();
        anterior();
    }
}

onMounted(() => window.addEventListener('keydown', alTeclado));
onBeforeUnmount(() => window.removeEventListener('keydown', alTeclado));
</script>

<template>
    <Teleport to="body">
        <div
            v-if="tourActivo"
            class="fixed inset-0 z-[9998]"
            role="dialog"
            aria-modal="true"
            :aria-label="tourActivo.titulo"
        >
            <!-- Fondo: oscurece todo menos el hueco del elemento resaltado.
                 Sin elemento, el oscurecido es parejo. -->
            <div
                v-if="!rect"
                class="absolute inset-0 bg-slate-950/65 transition-opacity duration-300"
            />
            <div
                class="pointer-events-none absolute rounded-xl border-2 border-primary shadow-[0_0_0_9999px_rgba(2,6,23,0.65)] transition-all duration-300 ease-out"
                :style="estiloSpotlight"
            />
            <div
                class="pointer-events-none absolute animate-pulse rounded-xl border-2 border-primary/50 transition-all duration-300 ease-out"
                :style="estiloSpotlight"
            />

            <!-- Cambiando de pantalla / esperando a que aparezca el elemento -->
            <!-- Llaves distintas en cada rama: con la llave numérica del paso
                 (0 en el primero) Vue confundía la caja con este aviso (mismo
                 tag, misma llave), reutilizaba el elemento y dejaba de poder
                 actualizarlo ("reading 'style'" en cada cuadro): la guía se
                 quedaba congelada. -->
            <div
                v-if="cargando"
                key="tour-cargando"
                class="absolute top-1/2 left-1/2 z-[9999] flex -translate-x-1/2 -translate-y-1/2 items-center gap-3 rounded-xl border bg-popover px-4 py-3 text-sm text-popover-foreground shadow-2xl"
                aria-live="polite"
            >
                <Loader2 class="size-4 animate-spin text-primary" />
                <span>
                    {{
                        paso?.seccion
                            ? `Abriendo ${paso.seccion}…`
                            : 'Preparando la guía…'
                    }}
                </span>
                <!-- Nunca se bloquea el avance: si un paso tarda, se puede saltar. -->
                <button
                    type="button"
                    class="ml-2 text-xs font-medium text-primary underline-offset-2 hover:underline"
                    @click="saltarPaso"
                >
                    Saltar paso
                </button>
                <button
                    type="button"
                    class="text-xs text-muted-foreground underline-offset-2 hover:underline"
                    @click="finalizar"
                >
                    Salir
                </button>
            </div>

            <div
                v-else-if="estado === 'showing'"
                ref="tooltip"
                :key="`tour-paso-${pasoActual}`"
                class="absolute z-[9999] flex max-h-[calc(100dvh-1.5rem)] animate-in flex-col overflow-hidden rounded-2xl border bg-popover text-popover-foreground shadow-2xl duration-200 fade-in"
                :class="
                    posicion.modo === 'hoja-abajo'
                        ? 'slide-in-from-bottom-4'
                        : posicion.modo === 'hoja-arriba'
                          ? 'slide-in-from-top-4'
                          : 'zoom-in-95'
                "
                :data-lado="posicion.lado ?? posicion.modo"
                :style="estiloTooltip"
                aria-live="polite"
            >
                <div class="h-1 w-full bg-muted">
                    <div
                        class="h-full bg-primary transition-[width] duration-300 ease-out"
                        :style="{ width: `${porcentaje}%` }"
                    />
                </div>

                <div class="flex min-h-0 flex-col gap-3 overflow-y-auto p-4">
                    <div class="flex items-center justify-between gap-2">
                        <div
                            class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                        >
                            <span
                                v-if="paso?.seccion"
                                class="truncate rounded-full bg-primary/10 px-2 py-0.5 font-medium text-primary"
                            >
                                {{ paso.seccion }}
                            </span>
                            <span
                                v-if="
                                    secciones.length > 2 && indiceSeccion >= 0
                                "
                                class="shrink-0"
                            >
                                Módulo {{ indiceSeccion + 1 }}/{{
                                    secciones.length
                                }}
                                ·
                            </span>
                            <span class="shrink-0 tabular-nums">
                                Paso {{ pasoActual + 1 }} de {{ totalPasos }}
                            </span>
                        </div>
                        <button
                            type="button"
                            class="-m-1 shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                            aria-label="Cerrar guía"
                            @click="finalizar"
                        >
                            <X class="size-4" />
                        </button>
                    </div>

                    <div class="space-y-1.5">
                        <div class="flex items-center gap-2">
                            <span
                                v-if="esCentrado"
                                class="flex size-8 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary"
                            >
                                <Compass class="size-4" />
                            </span>
                            <p
                                class="font-semibold text-balance"
                                :class="esCentrado ? 'text-base' : 'text-sm'"
                            >
                                {{ paso?.titulo }}
                            </p>
                        </div>
                        <p class="text-sm text-pretty text-muted-foreground">
                            {{ paso?.texto }}
                        </p>
                    </div>

                    <div
                        v-if="paso?.consejo"
                        class="flex gap-2 rounded-lg border border-amber-500/30 bg-amber-500/10 p-2.5 text-xs text-pretty text-foreground"
                    >
                        <Lightbulb
                            class="mt-0.5 size-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <span>{{ paso.consejo }}</span>
                    </div>
                </div>

                <div
                    class="flex flex-wrap items-center justify-between gap-2 border-t bg-muted/30 px-4 py-2.5"
                >
                    <button
                        v-if="mostrarSaltar"
                        type="button"
                        class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        @click="saltarSeccion"
                    >
                        Saltar módulo
                    </button>
                    <button
                        v-else-if="!esUltimoPaso"
                        type="button"
                        class="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                        @click="finalizar"
                    >
                        Salir de la guía
                    </button>
                    <span v-else />

                    <div class="ml-auto flex items-center gap-1.5">
                        <Button
                            v-if="!esPrimerPaso"
                            variant="ghost"
                            size="sm"
                            @click="anterior"
                        >
                            Atrás
                        </Button>
                        <Button size="sm" @click="siguiente">
                            {{
                                esUltimoPaso
                                    ? 'Terminar'
                                    : esPrimerPaso
                                      ? 'Comenzar'
                                      : 'Siguiente'
                            }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
