<script setup lang="ts">
import { computed } from 'vue';
import { numero, pct } from '@/lib/rotacion';
import { cn } from '@/lib/utils';

/**
 * Barras horizontales del dashboard de rotación (sucursales, causales):
 * nombre a la izquierda, barra proporcional al mayor y «n · %» al final.
 * Cada fila es un botón que abre el histórico ya filtrado.
 */
export type BarraHorizontal = {
    clave: string;
    etiqueta: string;
    valor: number;
    porcentaje: number | null;
    /** Clase de fondo (bg-*) de la barra. */
    color: string;
    accesible: string;
    /** Sin clic (p. ej. bajas del Excel sin desglose). */
    soloLectura?: boolean;
};

const props = withDefaults(
    defineProps<{
        barras: BarraHorizontal[];
        etiquetaGrafica: string;
        /** Ancho de la columna de nombres (clase w-*). */
        anchoEtiqueta?: string;
        mayusculas?: boolean;
    }>(),
    { anchoEtiqueta: 'w-28 sm:w-40', mayusculas: false },
);

const emit = defineEmits<{ seleccionar: [clave: string] }>();

const maximo = computed(() => Math.max(1, ...props.barras.map((b) => b.valor)));
</script>

<template>
    <ul class="flex flex-col gap-1" :aria-label="etiquetaGrafica">
        <li v-for="b in barras" :key="b.clave">
            <component
                :is="b.soloLectura ? 'div' : 'button'"
                :type="b.soloLectura ? undefined : 'button'"
                :class="
                    cn(
                        'group flex w-full items-center gap-2.5 rounded-lg px-1 py-[3px] text-left',
                        !b.soloLectura &&
                            'cursor-pointer transition-colors hover:bg-crema/50 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-oro',
                    )
                "
                :aria-label="b.accesible"
                :title="b.accesible"
                @click="
                    b.soloLectura ? undefined : emit('seleccionar', b.clave)
                "
            >
                <span
                    :class="
                        cn(
                            'line-clamp-2 shrink-0 text-right text-[11px] leading-tight text-foreground/80 sm:text-xs',
                            mayusculas && 'tracking-wide uppercase',
                            anchoEtiqueta,
                        )
                    "
                    >{{ b.etiqueta }}</span
                >
                <span
                    class="flex min-w-0 flex-1 items-center gap-2 pr-[5.5rem]"
                >
                    <span
                        :class="
                            cn(
                                'h-3.5 shrink-0 rounded-[5px] transition-[filter] duration-150 group-hover:brightness-110 sm:h-4',
                                b.color,
                            )
                        "
                        :style="{
                            width: `${(b.valor * 100) / maximo}%`,
                            minWidth: b.valor > 0 ? '0.25rem' : '0',
                        }"
                    />
                    <span
                        class="shrink-0 text-[11px] whitespace-nowrap tabular-nums sm:text-xs"
                    >
                        <strong class="font-semibold text-foreground">{{
                            numero(b.valor)
                        }}</strong>
                        <span class="text-muted-foreground">
                            · {{ pct(b.porcentaje) }}</span
                        >
                    </span>
                </span>
            </component>
        </li>
    </ul>
</template>
