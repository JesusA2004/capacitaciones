<script setup lang="ts">
import { computed } from 'vue';
import { decimal1, numero } from '@/lib/rotacion';
import { cn } from '@/lib/utils';

/**
 * Barras verticales del dashboard de rotación (bajas por mes, antigüedad):
 * etiqueta de valor sobre cada barra, rejilla con escala «redonda» y, si se
 * indica, la línea punteada del promedio. Cada barra es un botón que abre
 * el histórico ya filtrado. Colores solo con clases de la paleta (tokens).
 */
export type BarraVertical = {
    clave: string;
    etiqueta: string;
    valor: number | null;
    /** Clase de fondo (bg-*) de la barra. */
    color: string;
    /** Texto para lectores de pantalla y tooltip. */
    accesible: string;
    /** Texto en lugar de la barra cuando no hay dato (valor null). */
    sinDato?: string;
};

const props = withDefaults(
    defineProps<{
        barras: BarraVertical[];
        promedio?: number | null;
        etiquetaGrafica: string;
        alto?: string;
    }>(),
    { promedio: null, alto: 'h-56' },
);

const emit = defineEmits<{ seleccionar: [clave: string] }>();

/** Escala con 3–5 marcas «redondas» (1, 2, 5, 10, 20, 25, 50…). */
const escala = computed(() => {
    const max = Math.max(
        1,
        props.promedio ?? 0,
        ...props.barras.map((b) => b.valor ?? 0),
    );
    const pasos = [1, 2, 4, 5, 8, 10, 20, 25, 50, 100, 200, 250, 500, 1000];
    const paso = pasos.find((p) => max / p <= 5) ?? Math.ceil(max / 5);
    const tope = Math.ceil(max / paso) * paso;
    const marcas: number[] = [];

    for (let v = 0; v <= tope; v += paso) {
        marcas.push(v);
    }

    return { tope, marcas };
});

function porcentajeAlto(valor: number): string {
    return `${(valor * 100) / escala.value.tope}%`;
}
</script>

<template>
    <figure class="flex min-w-0 flex-col pt-4" :aria-label="etiquetaGrafica">
        <div :class="cn('relative flex gap-2', alto)">
            <!-- Eje Y -->
            <div
                class="relative w-6 shrink-0 text-right text-[10px] text-muted-foreground tabular-nums"
                aria-hidden="true"
            >
                <span
                    v-for="m in escala.marcas"
                    :key="m"
                    class="absolute right-0 translate-y-1/2 leading-none"
                    :style="{ bottom: porcentajeAlto(m) }"
                    >{{ m }}</span
                >
            </div>

            <div class="relative min-w-0 flex-1">
                <!-- Rejilla -->
                <div
                    v-for="m in escala.marcas"
                    :key="`r-${m}`"
                    class="pointer-events-none absolute inset-x-0 border-t border-border/70"
                    :style="{ bottom: porcentajeAlto(m) }"
                    aria-hidden="true"
                />

                <!-- Barras -->
                <ul
                    class="absolute inset-0 flex items-end justify-around gap-1 sm:gap-2"
                >
                    <li
                        v-for="b in barras"
                        :key="b.clave"
                        class="flex h-full max-w-14 min-w-0 flex-1 flex-col items-center justify-end"
                    >
                        <span
                            v-if="b.valor === null"
                            class="grid h-1/3 w-full place-items-center rounded-md border border-dashed border-border text-center text-[9px] leading-tight text-muted-foreground"
                            :title="b.accesible"
                            >{{ b.sinDato ?? 'Sin datos' }}</span
                        >
                        <button
                            v-else
                            type="button"
                            class="group relative flex w-full cursor-pointer flex-col justify-end rounded-t-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-oro"
                            :style="{ height: porcentajeAlto(b.valor) }"
                            :aria-label="b.accesible"
                            :title="b.accesible"
                            @click="emit('seleccionar', b.clave)"
                        >
                            <span
                                class="absolute inset-x-0 bottom-full z-10 mb-0.5 text-center text-xs font-semibold text-foreground tabular-nums"
                                >{{ numero(b.valor) }}</span
                            >
                            <span
                                :class="
                                    cn(
                                        'block w-full flex-1 rounded-t-md transition-[filter] duration-150 group-hover:brightness-110',
                                        b.valor === 0 ? 'min-h-px' : 'min-h-1',
                                        b.color,
                                    )
                                "
                            />
                        </button>
                    </li>
                </ul>

                <!-- Promedio -->
                <div
                    v-if="promedio !== null && promedio > 0"
                    class="pointer-events-none absolute inset-x-0 border-t-2 border-dashed border-bronce/80"
                    :style="{ bottom: porcentajeAlto(promedio) }"
                    aria-hidden="true"
                />
            </div>
        </div>

        <!-- Eje X -->
        <div class="mt-1.5 flex gap-2" aria-hidden="true">
            <span class="w-6 shrink-0" />
            <div class="flex min-w-0 flex-1 justify-around gap-1 sm:gap-2">
                <span
                    v-for="b in barras"
                    :key="`x-${b.clave}`"
                    class="max-w-14 min-w-0 flex-1 truncate text-center text-[11px] text-muted-foreground"
                    >{{ b.etiqueta }}</span
                >
            </div>
        </div>

        <figcaption v-if="promedio !== null" class="sr-only">
            Promedio {{ decimal1(promedio) }} bajas por mes.
        </figcaption>
    </figure>
</template>
