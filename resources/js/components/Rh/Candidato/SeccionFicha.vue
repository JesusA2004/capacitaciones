<script setup lang="ts">
import { ChevronDown } from '@lucide/vue';
import type { Component } from 'vue';
import { onMounted, ref, useId } from 'vue';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';

/**
 * Bloque de la ficha del candidato: encabezado con ícono en círculo pastel,
 * título y una línea que explica qué hay dentro. Entra escalonado según
 * `indice` (la regla global de reduced-motion la apaga) y se eleva un poco
 * al pasar el mouse para que se sienta vivo, sin mover el layout.
 *
 * Con `plegable` el encabezado abre y cierra el contenido (acordeón): en
 * escritorio empieza abierto y en móvil cerrado, mostrando `resumen` en una
 * línea. El contenido plegado sigue montado (no se pierden diálogos ni
 * formularios a medias).
 */
const props = withDefaults(
    defineProps<{
        titulo: string;
        icono: Component;
        descripcion?: string | null;
        tono?: Tono;
        indice?: number;
        /** Número que acompaña al título (archivos, evaluaciones…). */
        contador?: number | null;
        etiquetaAria?: string | null;
        plegable?: boolean;
        /** Línea que se ve cuando está plegada. */
        resumen?: string | null;
        /** En móvil también empieza abierta. */
        abiertaEnMovil?: boolean;
    }>(),
    {
        descripcion: null,
        tono: 'primario',
        indice: 0,
        contador: null,
        etiquetaAria: null,
        plegable: false,
        resumen: null,
        abiertaEnMovil: false,
    },
);

const abierta = ref(true);
const idContenido = useId();

onMounted(() => {
    if (
        props.plegable &&
        !props.abiertaEnMovil &&
        window.matchMedia('(max-width: 639px)').matches
    ) {
        abierta.value = false;
    }
});
</script>

<template>
    <section
        class="flex min-w-0 aparece-escalonado flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm transicion-ui hover:shadow-md sm:p-5"
        :style="{ '--i': indice }"
        :aria-label="etiquetaAria ?? titulo"
    >
        <header class="flex flex-wrap items-start gap-3">
            <span
                class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                :class="TONO_SUAVE[tono]"
                aria-hidden="true"
            >
                <component :is="icono" class="size-5" />
            </span>
            <div class="min-w-0 flex-1">
                <h2
                    class="flex flex-wrap items-center gap-2 text-base leading-tight font-semibold"
                >
                    {{ titulo }}
                    <span
                        v-if="contador !== null"
                        class="rounded-full bg-crema px-2 py-0.5 text-xs font-semibold text-bronce tabular-nums"
                        >{{ contador }}</span
                    >
                </h2>
                <p
                    v-if="plegable && !abierta && resumen"
                    class="mt-0.5 text-xs leading-snug break-words text-muted-foreground"
                >
                    {{ resumen }}
                </p>
                <p
                    v-else-if="descripcion"
                    class="mt-0.5 text-xs leading-snug break-words text-muted-foreground"
                >
                    {{ descripcion }}
                </p>
            </div>
            <div
                v-if="$slots.acciones"
                class="flex flex-wrap items-center gap-2"
            >
                <slot name="acciones" />
            </div>
            <button
                v-if="plegable"
                type="button"
                class="inline-flex min-h-9 shrink-0 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-[var(--mrl-petroleo)] transicion-ui hover:bg-crema/60 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="abierta"
                :aria-controls="idContenido"
                @click="abierta = !abierta"
            >
                {{ abierta ? 'Ocultar' : 'Ver detalle' }}
                <ChevronDown
                    class="size-4 transicion-ui"
                    :class="abierta ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </button>
        </header>
        <div
            v-show="abierta"
            :id="idContenido"
            class="flex min-w-0 flex-col gap-4"
        >
            <slot />
        </div>
    </section>
</template>
