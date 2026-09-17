<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { useTourGuiado } from '@/composables/useTourGuiado';

/**
 * Overlay de "spotlight" para el tour guiado: recalcula la posición del
 * elemento real de la pantalla en cada frame (en vez de listeners de
 * scroll/resize) para que el resaltado siga perfectamente cualquier
 * animación, scroll o cambio de layout mientras el tour está activo. Se
 * monta UNA sola vez en `AppSidebarLayout.vue`; el estado vive en
 * `useTourGuiado()` para que cualquier botón de ayuda pueda iniciar un tour
 * sin acoplarse a este componente.
 *
 * Deliberadamente sólo apunta a elementos SIEMPRE presentes al cargar la
 * página (botones, filtros, tarjetas, menú) — nunca a campos dentro de un
 * modal que el usuario todavía no abrió, para no depender de forzar la
 * apertura de diálogos de cada pantalla.
 */
const {
    tourActivo,
    paso,
    pasoActual,
    totalPasos,
    esUltimoPaso,
    esPrimerPaso,
    siguiente,
    anterior,
    finalizar,
} = useTourGuiado();

const rect = ref<DOMRect | null>(null);
let rafId: number | null = null;

function actualizarRect(): void {
    const selector = paso.value?.selector;
    const elemento = selector ? document.querySelector(selector) : null;
    rect.value = elemento ? elemento.getBoundingClientRect() : null;
    rafId = requestAnimationFrame(actualizarRect);
}

function iniciarSeguimiento(): void {
    detenerSeguimiento();
    rafId = requestAnimationFrame(actualizarRect);
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
        if (activo) {
            iniciarSeguimiento();
        } else {
            detenerSeguimiento();
            rect.value = null;
        }
    },
    { immediate: true },
);

watch(paso, (actual) => {
    if (!actual) {
return;
}

    const elemento = document.querySelector(actual.selector);

    if (!elemento) {
return;
}

    // Si el objetivo es más alto que la pantalla, centrarlo lo dejaría con
    // ambos extremos fuera de vista; mejor alinear su borde superior.
    const esAltoCompleto =
        elemento.getBoundingClientRect().height > window.innerHeight * 0.85;
    elemento.scrollIntoView({
        behavior: 'smooth',
        block: esAltoCompleto ? 'start' : 'center',
    });
});

onBeforeUnmount(detenerSeguimiento);

const MARGEN = 10;
const ANCHO_TOOLTIP = 340;

const estiloSpotlight = computed(() => {
    if (!rect.value) {
return { display: 'none' };
}

    return {
        top: `${rect.value.top - MARGEN}px`,
        left: `${rect.value.left - MARGEN}px`,
        width: `${rect.value.width + MARGEN * 2}px`,
        height: `${rect.value.height + MARGEN * 2}px`,
    };
});

/** Alto aproximado del tooltip (título + texto de 2-3 líneas + botones). */
const ALTO_TOOLTIP_ESTIMADO = 180;

const estiloTooltip = computed(() => {
    const vw = window.innerWidth;
    const vh = window.innerHeight;
    const anchoReal = Math.min(ANCHO_TOOLTIP, vw - 32);

    if (!rect.value) {
        return {
            top: `${vh / 2}px`,
            left: `${vw / 2}px`,
            width: `${anchoReal}px`,
            transform: 'translate(-50%, -50%)',
        };
    }

    // El objetivo es más alto que la pantalla (p. ej. una sección completa de
    // tarjetas): sus bordes probablemente están fuera de vista, así que no
    // tiene caso "colocar arriba/abajo" de ellos. Se fija abajo, siempre visible.
    if (rect.value.height > vh * 0.85) {
        return {
            left: `${Math.max(16, (vw - anchoReal) / 2)}px`,
            top: `${vh - 16}px`,
            width: `${anchoReal}px`,
            transform: 'translateY(-100%)',
        };
    }

    const espacioAbajo = vh - rect.value.bottom;
    const espacioArriba = rect.value.top;
    const colocarAbajo = espacioAbajo >= 190 || espacioAbajo >= espacioArriba;

    let left = rect.value.left + rect.value.width / 2 - anchoReal / 2;
    left = Math.max(16, Math.min(left, vw - anchoReal - 16));

    let top = colocarAbajo
        ? rect.value.bottom + MARGEN + 10
        : rect.value.top - MARGEN - 10;
    // Nunca dejar que el tooltip quede fuera de la pantalla, incluso si el
    // objetivo está parcialmente fuera de vista tras el scroll.
    top = colocarAbajo
        ? Math.min(top, vh - 16)
        : Math.max(top, Math.min(ALTO_TOOLTIP_ESTIMADO, vh - 16));

    return {
        left: `${left}px`,
        top: `${top}px`,
        width: `${anchoReal}px`,
        transform: colocarAbajo ? 'translateY(0)' : 'translateY(-100%)',
    };
});

function alTeclado(evento: KeyboardEvent): void {
    if (!tourActivo.value) {
return;
}

    if (evento.key === 'Escape') {
finalizar();
}

    if (evento.key === 'ArrowRight' || evento.key === 'Enter') {
siguiente();
}

    if (evento.key === 'ArrowLeft') {
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
            <div
                class="pointer-events-none absolute rounded-xl border-2 border-primary shadow-[0_0_0_9999px_rgba(15,23,42,0.65)] transition-all duration-300 ease-out"
                :style="estiloSpotlight"
            />
            <div
                class="pointer-events-none absolute animate-pulse rounded-xl border-2 border-primary/50 transition-all duration-300 ease-out"
                :style="estiloSpotlight"
            />

            <div
                class="animate-in fade-in zoom-in-95 absolute z-[9999] rounded-xl border bg-popover p-4 text-popover-foreground shadow-2xl transition-[top,left] duration-300 ease-out"
                :style="estiloTooltip"
            >
                <div class="flex items-start justify-between gap-2">
                    <p class="text-sm font-semibold">{{ paso?.titulo }}</p>
                    <button
                        type="button"
                        class="-m-1 shrink-0 rounded p-1 text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        aria-label="Cerrar guía"
                        @click="finalizar"
                    >
                        <X class="size-4" />
                    </button>
                </div>
                <p class="mt-1.5 text-sm text-pretty text-muted-foreground">
                    {{ paso?.texto }}
                </p>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <div class="flex items-center gap-1">
                        <span
                            v-for="i in totalPasos"
                            :key="i"
                            class="size-1.5 rounded-full transition-colors"
                            :class="
                                i - 1 === pasoActual ? 'bg-primary' : 'bg-muted'
                            "
                        />
                    </div>
                    <div class="flex items-center gap-1.5">
                        <Button
                            v-if="!esPrimerPaso"
                            variant="ghost"
                            size="sm"
                            @click="anterior"
                        >
                            Atrás
                        </Button>
                        <Button size="sm" @click="siguiente">
                            {{ esUltimoPaso ? 'Terminar' : 'Siguiente' }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </Teleport>
</template>
