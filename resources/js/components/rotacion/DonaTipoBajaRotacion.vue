<script setup lang="ts">
import { computed } from 'vue';
import { CLASIFICACION_VISUAL, numero, pct } from '@/lib/rotacion';
import { cn } from '@/lib/utils';
import type { ClasificacionRotacion, ResumenRotacion } from '@/types';

/**
 * Dona «Tipo de baja» del dashboard: voluntaria / involuntaria / sin
 * clasificar, con el % de voluntarias al centro. La leyenda es la parte
 * accesible (botones que abren el histórico filtrado); los arcos solo
 * repiten la información visualmente.
 */
const props = defineProps<{ resumen: ResumenRotacion }>();

const emit = defineEmits<{ seleccionar: [clave: ClasificacionRotacion] }>();

const TRAZO: Record<ClasificacionRotacion, string> = {
    voluntaria: 'stroke-chart-1',
    involuntaria: 'stroke-chart-2',
    sin_clasificar: 'stroke-muted-foreground/30',
};

const segmentos = computed(() => {
    const r = props.resumen;
    const lista: { clave: ClasificacionRotacion; bajas: number }[] = [
        { clave: 'voluntaria', bajas: r.voluntarias.bajas },
        { clave: 'involuntaria', bajas: r.involuntarias.bajas },
        { clave: 'sin_clasificar', bajas: r.sin_clasificar.bajas },
    ];
    const total = lista.reduce((s, x) => s + x.bajas, 0);
    let acumulado = 0;

    return lista.map((x) => {
        const largo = total > 0 ? (x.bajas * 100) / total : 0;
        const inicio = acumulado;
        acumulado += largo;

        return { ...x, largo, inicio };
    });
});
</script>

<template>
    <div class="flex flex-col items-center gap-3">
        <div class="relative aspect-square w-full max-w-[11.5rem]">
            <svg
                viewBox="0 0 120 120"
                class="size-full -rotate-90"
                aria-hidden="true"
            >
                <circle
                    cx="60"
                    cy="60"
                    r="46"
                    fill="none"
                    stroke-width="17"
                    class="stroke-muted"
                />
                <template v-for="s in segmentos" :key="s.clave">
                    <circle
                        v-if="s.largo > 0"
                        cx="60"
                        cy="60"
                        r="46"
                        fill="none"
                        stroke-width="17"
                        pathLength="100"
                        :stroke-dasharray="`${Math.max(0, s.largo - 0.6)} ${100 - Math.max(0, s.largo - 0.6)}`"
                        :stroke-dashoffset="-s.inicio"
                        :class="
                            cn(
                                'cursor-pointer transition-opacity hover:opacity-85',
                                TRAZO[s.clave],
                            )
                        "
                        @click="emit('seleccionar', s.clave)"
                    />
                </template>
            </svg>
            <div
                class="pointer-events-none absolute inset-0 grid place-content-center text-center"
            >
                <span
                    class="font-display text-[1.65rem] leading-none font-semibold text-primary tabular-nums"
                    >{{ pct(resumen.voluntarias.porcentaje) }}</span
                >
                <span class="mt-1 text-[11px] text-muted-foreground"
                    >voluntarias</span
                >
            </div>
        </div>

        <ul
            class="flex flex-wrap justify-center gap-x-3 gap-y-1"
            aria-label="Tipo de baja"
        >
            <li v-for="s in segmentos" :key="`l-${s.clave}`">
                <button
                    type="button"
                    class="inline-flex cursor-pointer items-center gap-1.5 rounded-md px-1 py-0.5 text-xs transition-colors hover:bg-crema/50 focus-visible:outline-2 focus-visible:outline-oro"
                    :aria-label="`${CLASIFICACION_VISUAL[s.clave].etiqueta}: ${s.bajas} bajas. Ver colaboradores`"
                    @click="emit('seleccionar', s.clave)"
                >
                    <span
                        :class="
                            cn(
                                'size-2.5 rounded-[3px]',
                                CLASIFICACION_VISUAL[s.clave].fondo,
                            )
                        "
                        aria-hidden="true"
                    />
                    {{ CLASIFICACION_VISUAL[s.clave].etiqueta }}
                    <strong class="font-semibold tabular-nums">{{
                        numero(s.bajas)
                    }}</strong>
                </button>
            </li>
        </ul>
    </div>
</template>
