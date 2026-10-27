<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Loader2,
    Maximize2,
    Minimize2,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { useElementSize, useFullscreen, onKeyStroke } from '@vueuse/core';
import type { PDFDocumentProxy } from 'pdfjs-dist';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { Button } from '@/components/ui/button';
import { abrirPdf, renderizarEnLienzo } from '@/lib/pdf';

/**
 * Visor de un PDF REAL con pdf.js: dibuja la página tal cual (diseño,
 * logos, tipografía del documento), una a la vez y solo la que se ve; la
 * siguiente se precarga. Sin reconstrucciones en HTML. Lo usan «Presentar
 * inducción» y la inducción del colaborador (que escucha `pagina` para
 * registrar su avance).
 */
const props = withDefaults(
    defineProps<{
        url: string;
        titulo: string;
        paginaInicial?: number;
    }>(),
    { paginaInicial: 1 },
);

const emit = defineEmits<{
    /** Página que quedó dibujada en pantalla (no solo solicitada). */
    pagina: [numero: number, total: number];
}>();

const contenedor = ref<HTMLElement | null>(null);
const lienzoCaja = ref<HTMLElement | null>(null);
const lienzo = ref<HTMLCanvasElement | null>(null);
const documento = shallowRef<PDFDocumentProxy | null>(null);
const total = ref(0);
const actual = ref(Math.max(1, props.paginaInicial));
const zoom = ref(1);
const cargando = ref(true);
const dibujando = ref(false);
const fallo = ref(false);

const { width: anchoCaja } = useElementSize(lienzoCaja);
const { isFullscreen, toggle: alternarPresentacion } =
    useFullscreen(contenedor);

const PASOS_ZOOM = [0.5, 0.75, 1, 1.25, 1.5, 2];
const porcentajeZoom = computed(() => Math.round(zoom.value * 100));

async function cargar() {
    cargando.value = true;
    fallo.value = false;

    try {
        const doc = await abrirPdf(props.url);
        documento.value = doc;
        total.value = doc.numPages;
        actual.value = Math.min(actual.value, doc.numPages);
    } catch {
        fallo.value = true;
    } finally {
        cargando.value = false;
    }
}

let intentosAncho = 0;

async function dibujar() {
    const doc = documento.value;

    await nextTick();

    if (!doc || !lienzo.value) {
        return;
    }

    // El ancho del contenedor puede tardar un frame en estar disponible
    // (recién montado, dentro de una transición, o al volver de pantalla
    // completa). Usamos la medición del ResizeObserver y, si aún no reporta,
    // el ancho real del nodo. Si todavía mide 0, reintentamos en los siguientes
    // frames en vez de dibujar en blanco; el watch de tamaño lo retoma si el
    // contenedor aparece más tarde.
    const ancho =
        anchoCaja.value > 0 ? anchoCaja.value : (lienzoCaja.value?.clientWidth ?? 0);

    if (ancho <= 0) {
        if (intentosAncho < 30) {
            intentosAncho += 1;
            requestAnimationFrame(() => void dibujar());
        }

        return;
    }

    intentosAncho = 0;

    const numero = actual.value;
    dibujando.value = true;

    try {
        const pagina = await doc.getPage(numero);
        const base = pagina.getViewport({ scale: 1 });
        // Ajusta al ancho disponible (tope para no pixelar en monitores
        // enormes) y aplica el zoom elegido; nítido según la pantalla.
        const anchoCss = Math.min(ancho, 1280) * zoom.value;
        const escalaCss = anchoCss / base.width;
        const densidad = window.devicePixelRatio || 1;
        const viewport = pagina.getViewport({ scale: escalaCss * densidad });

        await renderizarEnLienzo(pagina, lienzo.value, viewport, {
            width: `${base.width * escalaCss}px`,
            height: `${base.height * escalaCss}px`,
        });

        // Render correcto: limpia cualquier fallo transitorio previo para que
        // un reintento (p. ej. tras redimensionar) recupere la vista.
        fallo.value = false;

        if (numero === actual.value) {
            emit('pagina', numero, doc.numPages);
        }

        // Precarga la siguiente para que avanzar se sienta inmediato.
        if (numero < doc.numPages) {
            void doc.getPage(numero + 1);
        }
    } catch {
        fallo.value = true;
    } finally {
        dibujando.value = false;
    }
}

function ir(numero: number) {
    if (numero >= 1 && numero <= total.value && numero !== actual.value) {
        actual.value = numero;
        lienzoCaja.value?.scrollTo({ top: 0 });
    }
}

function cambiarZoom(direccion: 1 | -1) {
    const i = PASOS_ZOOM.indexOf(zoom.value);
    zoom.value =
        PASOS_ZOOM[
            Math.min(PASOS_ZOOM.length - 1, Math.max(0, i + direccion))
        ] ?? 1;
}

watch(
    [documento, actual, zoom, () => Math.round(anchoCaja.value)],
    () => void dibujar(),
);

onKeyStroke(['ArrowRight', 'PageDown'], (e) => {
    if (!(e.target instanceof HTMLInputElement)) {
        e.preventDefault();
        ir(actual.value + 1);
    }
});
onKeyStroke(['ArrowLeft', 'PageUp'], (e) => {
    if (!(e.target instanceof HTMLInputElement)) {
        e.preventDefault();
        ir(actual.value - 1);
    }
});

void cargar();

onBeforeUnmount(() => {
    void documento.value?.destroy();
});

defineExpose({ ir, total, actual });
</script>

<template>
    <section
        ref="contenedor"
        class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-2xl border bg-card"
        :class="isFullscreen && 'rounded-none border-0'"
        aria-label="Visor del documento"
    >
        <header
            class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b px-4 py-2.5"
        >
            <div class="mr-auto min-w-0">
                <p class="truncate text-sm font-semibold">{{ titulo }}</p>
                <p
                    class="text-xs text-muted-foreground tabular-nums"
                    aria-live="polite"
                >
                    <template v-if="total">
                        Página {{ actual }} de {{ total }}
                    </template>
                    <template v-else>Cargando…</template>
                </p>
            </div>
            <div class="flex items-center gap-0.5">
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label="Alejar"
                    :disabled="zoom <= PASOS_ZOOM[0]"
                    @click="cambiarZoom(-1)"
                >
                    <ZoomOut class="size-4" />
                </Button>
                <span class="w-11 text-center text-xs tabular-nums"
                    >{{ porcentajeZoom }}%</span
                >
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label="Acercar"
                    :disabled="zoom >= PASOS_ZOOM[PASOS_ZOOM.length - 1]"
                    @click="cambiarZoom(1)"
                >
                    <ZoomIn class="size-4" />
                </Button>
            </div>
            <Button size="sm" variant="outline" @click="alternarPresentacion()">
                <Minimize2 v-if="isFullscreen" class="size-4" />
                <Maximize2 v-else class="size-4" />
                {{ isFullscreen ? 'Salir' : 'Presentación' }}
            </Button>
        </header>

        <div
            ref="lienzoCaja"
            class="relative min-h-0 flex-1 overflow-auto bg-crema/60 p-3 sm:p-6"
        >
            <div
                v-if="cargando"
                class="flex h-full min-h-[50vh] items-center justify-center gap-2 text-sm text-muted-foreground"
            >
                <Loader2 class="size-4 animate-spin" /> Abriendo documento…
            </div>
            <p
                v-else-if="fallo"
                class="flex h-full min-h-[50vh] items-center justify-center text-center text-sm text-danger"
                role="alert"
            >
                No se pudo mostrar el documento. Revisa tu conexión e intenta de
                nuevo.
            </p>
            <canvas
                v-show="!cargando && !fallo"
                ref="lienzo"
                class="mx-auto block rounded-sm bg-white shadow-sm transition-opacity duration-150 motion-reduce:transition-none"
                :class="dibujando ? 'opacity-70' : 'opacity-100'"
                role="img"
                :aria-label="`${titulo}, página ${actual}`"
            />
        </div>

        <footer class="flex items-center gap-2 border-t px-4 py-2.5">
            <Button
                variant="outline"
                :disabled="actual <= 1 || !total"
                @click="ir(actual - 1)"
            >
                <ChevronLeft class="size-4" /> Anterior
            </Button>
            <div class="mx-auto flex items-center">
                <slot name="centro" />
            </div>
            <!-- En la última página, la acción de cierre (si la hay) toma el
                 lugar de «Siguiente»: siempre una sola acción primaria. -->
            <slot
                v-if="total > 0 && actual === total && $slots.ultima"
                name="ultima"
            />
            <Button
                v-else
                :disabled="actual >= total || !total"
                @click="ir(actual + 1)"
            >
                Siguiente <ChevronRight class="size-4" />
            </Button>
        </footer>
    </section>
</template>
