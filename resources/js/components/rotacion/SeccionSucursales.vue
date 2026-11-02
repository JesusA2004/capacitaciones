<script setup lang="ts">
import { ArrowRight, Building2, Info } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import MatrizCalorRotacion from '@/components/rotacion/MatrizCalorRotacion.vue';
import SparklineRotacion from '@/components/rotacion/SparklineRotacion.vue';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import SelectorVista from '@/components/ui/SelectorVista.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    CLASIFICACION_VISUAL,
    DEFINICIONES,
    decimal1,
    numero,
    pct,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import { cn } from '@/lib/utils';
import type { MatrizRotacion, SucursalRotacionFila } from '@/types';

/**
 * Ranking por sucursal (por bajas, índice o rotación temprana; el orden es
 * local) y el mapa sucursal × causal. Cada fila abre el histórico de esa
 * sucursal conservando los filtros.
 */
const props = defineProps<{
    sucursales: SucursalRotacionFila[];
    matriz: MatrizRotacion;
    conDetalle: boolean;
}>();

const nav = useNavegacionRotacion();
const escritorio = useMediaQuery('(min-width: 1024px)');

type OrdenSucursal = 'bajas' | 'indice' | 'temprana';
const orden = ref<OrdenSucursal>('bajas');
const ORDENES: { valor: OrdenSucursal; etiqueta: string }[] = [
    { valor: 'bajas', etiqueta: 'Por bajas' },
    { valor: 'indice', etiqueta: 'Por índice' },
    { valor: 'temprana', etiqueta: 'Por rotación temprana' },
];

const ordenadas = computed(() => {
    const valor = (s: SucursalRotacionFila): number | null =>
        orden.value === 'bajas'
            ? s.bajas
            : orden.value === 'indice'
              ? s.indice
              : s.temprana;

    return [...props.sucursales].sort((a, b) => {
        const va = valor(a);
        const vb = valor(b);

        if (va === null && vb === null) {
            return b.bajas - a.bajas;
        }

        if (va === null) {
            return 1;
        }

        if (vb === null) {
            return -1;
        }

        return vb - va || b.bajas - a.bajas;
    });
});

function partes(s: SucursalRotacionFila) {
    const total = Math.max(
        1,
        s.voluntarias + s.involuntarias + s.sin_clasificar,
    );

    return [
        { c: 'voluntaria' as const, n: s.voluntarias },
        { c: 'involuntaria' as const, n: s.involuntarias },
        { c: 'sin_clasificar' as const, n: s.sin_clasificar },
    ].map((p) => ({ ...p, ancho: (p.n / total) * 100 }));
}

function abrir(s: SucursalRotacionFila): void {
    nav.verHistorico({ d_sucursal: s.sucursal_id });
}

function textoIndice(s: SucursalRotacionFila): string {
    if (props.conDetalle) {
        return 'No aplica';
    }

    return s.indice === null ? 'Sin plantilla' : pct(s.indice);
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <section
            class="flex min-w-0 flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            aria-labelledby="titulo-ranking"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2
                        id="titulo-ranking"
                        class="flex items-center gap-1.5 font-semibold"
                    >
                        Ranking de sucursales
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="grid size-6 place-items-center rounded-full text-muted-foreground hover:bg-crema/60"
                                    aria-label="Cómo se calcula"
                                >
                                    <Info class="size-3.5" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent class="max-w-72 text-left">
                                Participación: {{ DEFINICIONES.participacion }}
                                Índice: con la plantilla de cada sucursal; el
                                global no es la suma.
                            </TooltipContent>
                        </Tooltip>
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Toca una sucursal para ver sus bajas.
                    </p>
                </div>
                <SelectorVista
                    v-model="orden"
                    :opciones="ORDENES"
                    etiqueta="Ordenar sucursales"
                />
            </div>

            <EmptyState
                v-if="!sucursales.length"
                :icono="Building2"
                titulo="Sin bajas por sucursal"
                descripcion="Ninguna sucursal del alcance tiene bajas con estos filtros."
            />

            <!-- Escritorio: tabla -->
            <div v-else-if="escritorio" class="tabla-scroll">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr class="border-b border-border/60">
                            <th class="w-8 px-2 py-2 font-medium">#</th>
                            <th class="px-2 py-2 font-medium">Sucursal</th>
                            <th class="px-2 py-2 text-right font-medium">
                                Bajas
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                Participación
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                Índice
                            </th>
                            <th class="px-2 py-2 font-medium">
                                Vol. / Invol. / Sin clasif.
                            </th>
                            <th class="px-2 py-2 font-medium">
                                Causal principal
                            </th>
                            <th class="px-2 py-2 text-right font-medium">
                                Temprana
                            </th>
                            <th class="px-2 py-2 font-medium">Tendencia</th>
                        </tr>
                    </thead>
                    <ListaAnimada tag="tbody" :cantidad="ordenadas.length">
                        <tr
                            v-for="(s, i) in ordenadas"
                            :key="s.sucursal_id"
                            class="fila group cursor-pointer border-b border-border/40 transition-colors hover:bg-crema/40"
                            tabindex="0"
                            :aria-label="`${s.sucursal}: ${s.bajas} bajas. Ver bajas de la sucursal`"
                            @click="abrir(s)"
                            @keydown.enter.prevent="abrir(s)"
                        >
                            <td
                                class="px-2 py-2.5 text-xs text-muted-foreground tabular-nums"
                            >
                                {{ i + 1 }}
                            </td>
                            <td class="px-2 py-2.5">
                                <p class="font-medium">{{ s.sucursal }}</p>
                                <p
                                    class="flex items-center gap-1.5 text-[11px] text-muted-foreground"
                                >
                                    {{ s.empresa ?? '' }}
                                    <span
                                        v-if="s.es_corporativo"
                                        class="rounded-full bg-salvia px-1.5 text-primary"
                                        >Corporativo</span
                                    >
                                </p>
                            </td>
                            <td
                                class="px-2 py-2.5 text-right font-semibold tabular-nums"
                            >
                                {{ numero(s.bajas) }}
                                <span
                                    v-if="s.sin_desglose"
                                    class="block text-[11px] font-normal text-muted-foreground"
                                    >{{ s.sin_desglose }} sin desglose</span
                                >
                            </td>
                            <td class="px-2 py-2.5 text-right tabular-nums">
                                {{ pct(s.participacion) }}
                            </td>
                            <td
                                class="px-2 py-2.5 text-right tabular-nums"
                                :class="
                                    s.indice === null &&
                                    'text-xs text-muted-foreground'
                                "
                            >
                                {{ textoIndice(s) }}
                                <span
                                    v-if="s.plantilla_promedio !== null"
                                    class="block text-[11px] text-muted-foreground"
                                    >plantilla
                                    {{ decimal1(s.plantilla_promedio) }}</span
                                >
                            </td>
                            <td class="px-2 py-2.5">
                                <div
                                    class="flex h-2.5 w-28 overflow-hidden rounded-full bg-muted"
                                    :aria-label="`${s.voluntarias} voluntarias, ${s.involuntarias} involuntarias, ${s.sin_clasificar} sin clasificar`"
                                    role="img"
                                >
                                    <span
                                        v-for="p in partes(s)"
                                        :key="p.c"
                                        :class="CLASIFICACION_VISUAL[p.c].fondo"
                                        :style="{ width: `${p.ancho}%` }"
                                    />
                                </div>
                                <p
                                    class="mt-1 text-[11px] text-muted-foreground tabular-nums"
                                >
                                    {{ s.voluntarias }} /
                                    {{ s.involuntarias }} /
                                    {{ s.sin_clasificar }}
                                </p>
                            </td>
                            <td class="max-w-48 px-2 py-2.5 text-xs">
                                {{ s.causal_principal ?? '—' }}
                            </td>
                            <td class="px-2 py-2.5 text-right tabular-nums">
                                {{ pct(s.temprana) }}
                            </td>
                            <td class="px-2 py-2.5">
                                <div class="flex items-center gap-2">
                                    <SparklineRotacion :serie="s.serie" />
                                    <ArrowRight
                                        class="size-4 text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100"
                                        aria-hidden="true"
                                    />
                                </div>
                            </td>
                        </tr>
                    </ListaAnimada>
                </table>
            </div>

            <!-- Móvil: tarjetas -->
            <ListaAnimada
                v-else
                tag="ul"
                :cantidad="ordenadas.length"
                class="grid gap-2.5 sm:grid-cols-2"
            >
                <li v-for="(s, i) in ordenadas" :key="s.sucursal_id">
                    <button
                        type="button"
                        class="flex w-full tarjeta-interactiva flex-col gap-2.5 rounded-2xl border border-border/60 bg-card p-3.5 text-left"
                        @click="abrir(s)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    <span
                                        class="mr-1 text-xs text-muted-foreground tabular-nums"
                                        >{{ i + 1 }}.</span
                                    >{{ s.sucursal }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ s.empresa ?? '' }}
                                </p>
                            </div>
                            <SparklineRotacion :serie="s.serie" :ancho="72" />
                        </div>
                        <dl class="grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <dt class="text-muted-foreground">Bajas</dt>
                                <dd class="text-base font-bold tabular-nums">
                                    {{ numero(s.bajas) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Índice</dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ textoIndice(s) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Temprana</dt>
                                <dd class="font-semibold tabular-nums">
                                    {{ pct(s.temprana) }}
                                </dd>
                            </div>
                        </dl>
                        <div
                            class="flex h-2 overflow-hidden rounded-full bg-muted"
                            role="img"
                            :aria-label="`${s.voluntarias} voluntarias, ${s.involuntarias} involuntarias, ${s.sin_clasificar} sin clasificar`"
                        >
                            <span
                                v-for="p in partes(s)"
                                :key="p.c"
                                :class="CLASIFICACION_VISUAL[p.c].fondo"
                                :style="{ width: `${p.ancho}%` }"
                            />
                        </div>
                        <p
                            class="flex flex-wrap items-center gap-1.5 text-[11px] text-muted-foreground"
                        >
                            {{ pct(s.participacion) }} del total
                            <template v-if="s.causal_principal">
                                · Causal principal:
                                <ChipEstado
                                    :etiqueta="s.causal_principal"
                                    tono="neutro"
                                />
                            </template>
                        </p>
                    </button>
                </li>
            </ListaAnimada>

            <ul
                class="flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-muted-foreground"
                aria-label="Leyenda"
            >
                <li
                    v-for="c in [
                        'voluntaria',
                        'involuntaria',
                        'sin_clasificar',
                    ] as const"
                    :key="c"
                    class="flex items-center gap-1.5"
                >
                    <span
                        :class="
                            cn(
                                'size-2.5 rounded-sm',
                                CLASIFICACION_VISUAL[c].fondo,
                            )
                        "
                        aria-hidden="true"
                    />{{ CLASIFICACION_VISUAL[c].etiqueta }}
                </li>
            </ul>
        </section>

        <MatrizCalorRotacion :matriz="matriz" />
    </div>
</template>

<style scoped>
.fila:focus-visible {
    outline: 2px solid var(--pastel-oro);
    outline-offset: -2px;
}
</style>
