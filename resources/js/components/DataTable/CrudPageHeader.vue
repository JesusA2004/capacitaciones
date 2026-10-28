<script setup lang="ts">
import { useMediaQuery, useMutationObserver } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import { useEspacioAccionesPagina } from '@/composables/useEspacioAccionesPagina';

/**
 * Barra superior de una pantalla. En los módulos (listados, tableros) NO
 * se muestra título ni descripción — el breadcrumb del encabezado ya dice
 * dónde estás y el espacio vertical se deja para el contenido —; solo
 * quedan los botones de acción, alineados a la derecha. Si no hay botones,
 * no se dibuja nada.
 *
 * En pantallas de DETALLE (`detalle`), el título sí es información (folio
 * de la solicitud, nombre del candidato…) y se muestra.
 *
 * `titulo`/`descripcion`/`icono` se siguen recibiendo en módulos para
 * accesibilidad (aria-label) y para no romper las pantallas existentes.
 *
 * En módulos, desde md las acciones se mueven (Teleport) a la barra
 * superior junto al breadcrumb (#acciones-pagina en AppSidebarHeader): una
 * fila entera solo para un botón alineado a la derecha era espacio muerto.
 * En móvil se quedan en la página, donde sí caben.
 *
 * Solo suben a la barra si CABEN junto al breadcrumb
 * (useEspacioAccionesPagina): con varios botones largos en 768–1100 px se
 * quedan en la página, debajo del breadcrumb, acomodados en renglones —
 * nunca encima de él ni desbordando la barra.
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        descripcion?: string;
        icono?: Component;
        detalle?: boolean;
    }>(),
    { descripcion: undefined, icono: undefined, detalle: false },
);

const enEscritorio = useMediaQuery('(min-width: 768px)');
const { espacioAcciones } = useEspacioAccionesPagina();

const barra = useTemplateRef<HTMLElement>('barra');
/** Ancho que necesitan los botones en un solo renglón (gap-2 = 8 px). */
const anchoNecesario = ref(0);

function medir(): void {
    const hijos = barra.value
        ? (Array.from(barra.value.children) as HTMLElement[])
        : [];
    const visibles = hijos.filter((h) => h.offsetWidth > 0);

    anchoNecesario.value =
        visibles.reduce((total, h) => total + h.offsetWidth, 0) +
        Math.max(0, visibles.length - 1) * 8;
}

// Cada botón se observa por separado: cambia de ancho al cambiar su texto
// («Guardando…») o al aparecer/desaparecer, esté donde esté.
const observador =
    typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(medir);

function observarBotones(): void {
    if (!observador || !barra.value) {
        return;
    }

    observador.disconnect();

    for (const hijo of Array.from(barra.value.children)) {
        observador.observe(hijo);
    }

    medir();
}

watch(barra, observarBotones, { flush: 'post' });
useMutationObserver(barra, observarBotones, { childList: true });
onBeforeUnmount(() => observador?.disconnect());

const enBarraSuperior = computed(
    () => enEscritorio.value && anchoNecesario.value <= espacioAcciones.value,
);
</script>

<template>
    <div
        v-if="props.detalle"
        data-tour="encabezado"
        class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"
    >
        <div class="flex min-w-0 items-start gap-3">
            <!-- #icono reemplaza al ícono (p. ej. la foto de la persona). -->
            <slot v-if="$slots.icono" name="icono" />
            <span
                v-else-if="icono"
                class="flex size-11 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-primary/15 to-primary/5 text-primary shadow-sm ring-1 ring-primary/10"
            >
                <component :is="icono" class="size-5" />
            </span>
            <div class="min-w-0">
                <h2
                    class="text-xl font-semibold tracking-tight text-balance break-words sm:text-2xl"
                >
                    {{ titulo }}
                </h2>
                <p
                    v-if="descripcion"
                    class="mt-0.5 text-[15px] text-pretty text-muted-foreground"
                >
                    {{ descripcion }}
                </p>
            </div>
        </div>

        <div
            v-if="$slots.default"
            class="flex shrink-0 flex-wrap items-center gap-2"
        >
            <slot />
        </div>
    </div>

    <Teleport
        v-else-if="$slots.default"
        to="#acciones-pagina"
        defer
        :disabled="!enBarraSuperior"
    >
        <div
            ref="barra"
            data-tour="encabezado"
            role="toolbar"
            :aria-label="titulo"
            class="flex flex-wrap items-center justify-end gap-2 max-md:[&>*]:min-h-10 max-md:[&>*]:grow"
        >
            <slot />
        </div>
    </Teleport>
</template>
