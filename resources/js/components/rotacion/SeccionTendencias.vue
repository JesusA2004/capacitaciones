<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    ChevronDown,
    TrendingUp,
} from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { computed, ref } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    CLASIFICACION_VISUAL,
    DEFINICIONES,
    decimal1,
    etiquetaMes,
    mesCorto,
    numero,
    pct,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import { vTablaTarjetas } from '@/lib/tablaTarjetas';
import { cn } from '@/lib/utils';
import type {
    ClasificacionRotacion,
    MesTendenciaRotacion,
    TendenciaRotacion,
} from '@/types';

/**
 * Tendencia mensual: barras apiladas por tipo de salida, línea del promedio
 * y, si hay plantilla, el índice mensual en el eje derecho. Un mes sin
 * ninguna fuente se dibuja «Sin datos» (nunca 0). Tocar un mes abre su
 * detalle y desde ahí el histórico de ese mes.
 */
const props = defineProps<{
    tendencia: TendenciaRotacion;
    conDetalle: boolean;
}>();

const nav = useNavegacionRotacion();
const escritorio = useMediaQuery('(min-width: 768px)');

const meses = computed(() => props.tendencia.meses);
const totalPeriodo = computed(() =>
    meses.value.reduce((s, m) => s + m.total, 0),
);
const hayDatos = computed(() => meses.value.some((m) => !m.sin_datos));
const hayIndice = computed(
    () => !props.conDetalle && meses.value.some((m) => m.indice !== null),
);

/* --- Geometría ---------------------------------------------------------- */

const ANCHO = 960;
const ALTO = 340;
const M = { izq: 40, der: 48, arriba: 34, abajo: 54 };
const areaAlto = ALTO - M.arriba - M.abajo;
const areaAncho = ANCHO - M.izq - M.der;

function techoRedondo(v: number): number {
    if (v <= 4) {
        return 4;
    }

    const paso = Math.pow(10, Math.floor(Math.log10(v)));
    const n = v / paso;
    const redondo = n <= 2 ? 2 : n <= 5 ? 5 : 10;

    return Math.ceil(v / ((redondo * paso) / 4)) * ((redondo * paso) / 4);
}

const techo = computed(() =>
    techoRedondo(
        Math.max(
            props.tendencia.promedio ?? 0,
            ...meses.value.map((m) => m.total),
        ) * 1.1,
    ),
);
const techoIndice = computed(() =>
    Math.max(
        2,
        Math.ceil(Math.max(...meses.value.map((m) => m.indice ?? 0)) * 1.2),
    ),
);
const paso = computed(() => areaAncho / Math.max(1, meses.value.length));
const ancho = computed(() => Math.min(46, paso.value * 0.62));
const cx = (i: number) => M.izq + paso.value * (i + 0.5);
const y = (v: number) => M.arriba + areaAlto - (v / techo.value) * areaAlto;
const yIndice = (v: number) =>
    M.arriba + areaAlto - (v / techoIndice.value) * areaAlto;

const CAPAS: ClasificacionRotacion[] = [
    'voluntaria',
    'involuntaria',
    'sin_clasificar',
];

function valorCapa(m: MesTendenciaRotacion, c: ClasificacionRotacion): number {
    return c === 'voluntaria'
        ? m.voluntarias
        : c === 'involuntaria'
          ? m.involuntarias
          : m.sin_clasificar;
}

function segmentos(m: MesTendenciaRotacion) {
    let acumulado = 0;

    return CAPAS.map((c) => ({ c, v: valorCapa(m, c) }))
        .filter((s) => s.v > 0)
        .map((s) => {
            const arriba = y(acumulado + s.v);
            const alto = y(acumulado) - arriba;
            acumulado += s.v;

            return { ...s, y: arriba, alto: Math.max(alto, 1) };
        });
}

const lineasIndice = computed(() => {
    const tramos: string[] = [];
    let tramo: string[] = [];

    meses.value.forEach((m, i) => {
        if (m.indice === null) {
            if (tramo.length) {
                tramos.push(tramo.join(' '));
            }

            tramo = [];

            return;
        }

        tramo.push(`${cx(i)},${yIndice(m.indice)}`);
    });

    if (tramo.length) {
        tramos.push(tramo.join(' '));
    }

    return tramos;
});

const marcas = computed(() => [1, 2, 3, 4].map((n) => (techo.value / 4) * n));

/* --- Mes seleccionado --------------------------------------------------- */

const seleccionado = ref<MesTendenciaRotacion | null>(null);
const panelAbierto = computed({
    get: () => seleccionado.value !== null,
    set: (v: boolean) => {
        if (!v) {
            seleccionado.value = null;
        }
    },
});

function abrir(m: MesTendenciaRotacion): void {
    seleccionado.value = m;
}

function etiquetaAccesible(m: MesTendenciaRotacion): string {
    if (m.sin_datos) {
        return `${etiquetaMes(m.periodo, true)}: sin datos`;
    }

    return `${etiquetaMes(m.periodo, true)}: ${m.total} bajas, ${m.voluntarias} voluntarias, ${m.involuntarias} involuntarias, ${m.sin_clasificar} sin clasificar${m.indice !== null ? `, índice ${decimal1(m.indice)} %` : ''}. Abrir detalle`;
}

function partePeriodo(m: MesTendenciaRotacion): number | null {
    return totalPeriodo.value > 0
        ? Math.round((m.total * 1000) / totalPeriodo.value) / 10
        : null;
}

function porcentajeDe(n: number, total: number): number | null {
    return total > 0 ? Math.round((n * 1000) / total) / 10 : null;
}

const verTabla = ref(false);
</script>

<template>
    <div class="flex flex-col gap-4">
        <EmptyState
            v-if="!hayDatos"
            :icono="TrendingUp"
            titulo="Sin datos en el periodo"
            descripcion="Ningún mes del rango tiene bajas, totales del Excel o información del sistema."
        />

        <section
            v-else
            class="flex min-w-0 flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            aria-labelledby="titulo-tendencia"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="titulo-tendencia" class="font-semibold">
                        Bajas por mes y tipo de salida
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Toca un mes para ver su detalle y abrir sus bajas.
                        <template v-if="tendencia.mes_mayor">
                            Mes con más bajas:
                            <strong class="text-foreground">{{
                                etiquetaMes(tendencia.mes_mayor, true)
                            }}</strong
                            >.</template
                        >
                    </p>
                </div>
                <ul
                    class="flex flex-wrap gap-x-4 gap-y-1 text-xs"
                    aria-label="Leyenda"
                >
                    <li
                        v-for="c in CAPAS"
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
                    <li
                        v-if="tendencia.promedio !== null"
                        class="flex items-center gap-1.5"
                    >
                        <span
                            class="w-4 border-t-2 border-dashed border-bronce"
                            aria-hidden="true"
                        />Promedio mensual
                    </li>
                    <li v-if="hayIndice" class="flex items-center gap-1.5">
                        <span class="h-0.5 w-4 bg-info" aria-hidden="true" />
                        Índice mensual (%)
                    </li>
                </ul>
            </div>

            <div class="-mx-1 tabla-scroll px-1">
                <svg
                    :viewBox="`0 0 ${ANCHO} ${ALTO}`"
                    class="w-full min-w-[40rem] select-none"
                    role="group"
                    :aria-label="`Bajas mensuales de ${etiquetaMes(meses[0]?.periodo ?? null, true)} a ${etiquetaMes(meses.at(-1)?.periodo ?? null, true)}`"
                >
                    <g aria-hidden="true">
                        <g v-for="v in marcas" :key="v">
                            <line
                                :x1="M.izq"
                                :x2="ANCHO - M.der"
                                :y1="y(v)"
                                :y2="y(v)"
                                class="stroke-border"
                                stroke-dasharray="2 4"
                            />
                            <text
                                :x="M.izq - 8"
                                :y="y(v) + 4"
                                text-anchor="end"
                                class="fill-muted-foreground text-[11px] tabular-nums"
                            >
                                {{ Math.round(v) }}
                            </text>
                        </g>
                        <template v-if="hayIndice">
                            <text
                                v-for="n in 4"
                                :key="`i${n}`"
                                :x="ANCHO - M.der + 8"
                                :y="yIndice((techoIndice / 4) * n) + 4"
                                class="fill-info text-[11px] tabular-nums"
                            >
                                {{ decimal1((techoIndice / 4) * n) }}%
                            </text>
                        </template>
                        <line
                            :x1="M.izq"
                            :x2="ANCHO - M.der"
                            :y1="M.arriba + areaAlto"
                            :y2="M.arriba + areaAlto"
                            class="stroke-border"
                        />
                    </g>

                    <g
                        v-for="(m, i) in meses"
                        :key="m.periodo"
                        class="mes"
                        role="button"
                        tabindex="0"
                        :aria-label="etiquetaAccesible(m)"
                        @click="abrir(m)"
                        @keydown.enter.prevent="abrir(m)"
                        @keydown.space.prevent="abrir(m)"
                    >
                        <rect
                            :x="M.izq + paso * i + 2"
                            :y="M.arriba - 22"
                            :width="paso - 4"
                            :height="areaAlto + 22"
                            rx="10"
                            :class="
                                seleccionado?.periodo === m.periodo
                                    ? 'fill-crema/70'
                                    : 'fondo-mes fill-transparent'
                            "
                        />
                        <template v-if="m.sin_datos">
                            <rect
                                :x="cx(i) - ancho / 2"
                                :y="M.arriba + areaAlto - 46"
                                :width="ancho"
                                height="46"
                                rx="6"
                                class="fill-transparent stroke-muted-foreground/40"
                                stroke-dasharray="4 3"
                            />
                            <text
                                :x="cx(i)"
                                :y="M.arriba + areaAlto - 20"
                                text-anchor="middle"
                                class="fill-muted-foreground text-[10px]"
                            >
                                Sin datos
                            </text>
                        </template>
                        <template v-else>
                            <rect
                                v-for="s in segmentos(m)"
                                :key="s.c"
                                :x="cx(i) - ancho / 2"
                                :y="s.y"
                                :width="ancho"
                                :height="s.alto"
                                :class="
                                    cn(
                                        'barra',
                                        CLASIFICACION_VISUAL[s.c].relleno,
                                        (m.corte_parcial || m.en_curso) &&
                                            'opacity-70',
                                    )
                                "
                            />
                            <rect
                                v-if="tendencia.mes_mayor === m.periodo"
                                :x="cx(i) - ancho / 2 - 3"
                                :y="y(m.total) - 3"
                                :width="ancho + 6"
                                :height="M.arriba + areaAlto - y(m.total) + 3"
                                rx="4"
                                class="fill-transparent stroke-bronce"
                                stroke-width="2"
                            />
                            <text
                                :x="cx(i)"
                                :y="y(m.total) - 8"
                                text-anchor="middle"
                                :class="
                                    cn(
                                        'text-[12px] font-semibold tabular-nums',
                                        tendencia.mes_mayor === m.periodo
                                            ? 'fill-bronce'
                                            : 'fill-foreground',
                                    )
                                "
                            >
                                {{ m.total }}
                            </text>
                            <g v-if="m.discrepancias > 0">
                                <circle
                                    :cx="cx(i) + ancho / 2"
                                    :cy="y(m.total) - 18"
                                    r="7"
                                    class="fill-warning"
                                />
                                <text
                                    :x="cx(i) + ancho / 2"
                                    :y="y(m.total) - 14.5"
                                    text-anchor="middle"
                                    class="fill-card text-[10px] font-bold"
                                >
                                    !
                                </text>
                            </g>
                        </template>
                        <text
                            :x="cx(i)"
                            :y="M.arriba + areaAlto + 18"
                            text-anchor="middle"
                            class="fill-muted-foreground text-[11px]"
                        >
                            {{ mesCorto(m.mes)
                            }}{{
                                i === 0 || m.mes === 1
                                    ? ` ${String(m.anio).slice(2)}`
                                    : ''
                            }}
                        </text>
                        <text
                            v-if="m.en_curso || m.corte_parcial"
                            :x="cx(i)"
                            :y="M.arriba + areaAlto + 33"
                            text-anchor="middle"
                            class="fill-warning text-[10px] italic"
                        >
                            {{ m.en_curso ? 'En curso' : 'Parcial' }}
                        </text>
                    </g>

                    <g v-if="tendencia.promedio !== null" aria-hidden="true">
                        <line
                            :x1="M.izq"
                            :x2="ANCHO - M.der"
                            :y1="y(tendencia.promedio)"
                            :y2="y(tendencia.promedio)"
                            class="pointer-events-none stroke-bronce"
                            stroke-width="1.5"
                            stroke-dasharray="6 5"
                        />
                        <text
                            :x="M.izq + 4"
                            :y="y(tendencia.promedio) - 5"
                            class="pointer-events-none fill-bronce text-[11px]"
                        >
                            Promedio {{ decimal1(tendencia.promedio) }}
                        </text>
                    </g>

                    <g
                        v-if="hayIndice"
                        class="pointer-events-none"
                        aria-hidden="true"
                    >
                        <polyline
                            v-for="t in lineasIndice"
                            :key="t"
                            :points="t"
                            fill="none"
                            class="stroke-info"
                            stroke-width="2"
                        />
                        <template
                            v-for="(m, i) in meses"
                            :key="`p${m.periodo}`"
                        >
                            <circle
                                v-if="m.indice !== null"
                                :cx="cx(i)"
                                :cy="yIndice(m.indice)"
                                r="3.5"
                                class="fill-card stroke-info"
                                stroke-width="2"
                            />
                        </template>
                    </g>
                </svg>
            </div>

            <p
                v-if="meses.some((m) => m.discrepancias > 0)"
                class="flex items-center gap-1.5 text-xs text-warning"
            >
                <AlertTriangle class="size-3.5" aria-hidden="true" />
                «!» marca meses donde las bajas individuales y el Excel no
                coinciden. Ver «Calidad del dato» en Histórico.
            </p>

            <button
                type="button"
                class="inline-flex w-fit items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                :aria-expanded="verTabla"
                @click="verTabla = !verTabla"
            >
                Ver datos en tabla
                <ChevronDown
                    class="size-3.5 transition-transform"
                    :class="verTabla && 'rotate-180'"
                />
            </button>
            <div v-if="verTabla" class="tabla-scroll">
                <table v-tabla-tarjetas class="w-full text-left text-xs">
                    <thead class="text-muted-foreground">
                        <tr>
                            <th class="px-2 py-1.5 font-medium">Mes</th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Total
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Voluntarias
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Involuntarias
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Sin clasificar
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Plantilla prom.
                            </th>
                            <th class="px-2 py-1.5 text-right font-medium">
                                Índice
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in meses"
                            :key="m.periodo"
                            class="fila-interactiva border-t border-border/50"
                            tabindex="0"
                            :aria-label="`Ver el detalle de ${etiquetaMes(m.periodo)}`"
                            @click="abrir(m)"
                            @keydown.enter.prevent="abrir(m)"
                        >
                            <td class="px-2 py-1.5 font-medium">
                                {{ etiquetaMes(m.periodo) }}
                                <span
                                    v-if="m.en_curso || m.corte_parcial"
                                    class="text-warning"
                                    >·
                                    {{
                                        m.en_curso ? 'en curso' : 'parcial'
                                    }}</span
                                >
                            </td>
                            <template v-if="m.sin_datos">
                                <td
                                    colspan="6"
                                    class="px-2 py-1.5 text-muted-foreground"
                                >
                                    Sin datos
                                </td>
                            </template>
                            <template v-else>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    {{ numero(m.total) }}
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    {{ numero(m.voluntarias) }}
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    {{ numero(m.involuntarias) }}
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    {{ numero(m.sin_clasificar) }}
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    {{
                                        m.plantilla_promedio === null
                                            ? 'Sin plantilla'
                                            : decimal1(m.plantilla_promedio)
                                    }}
                                </td>
                                <td class="px-2 py-1.5 text-right tabular-nums">
                                    {{ pct(m.indice) }}
                                </td>
                            </template>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <Sheet v-model:open="panelAbierto">
            <SheetContent
                :side="escritorio ? 'right' : 'bottom'"
                :class="!escritorio && 'max-h-[85svh] rounded-t-3xl'"
            >
                <template v-if="seleccionado">
                    <SheetHeader>
                        <SheetTitle>{{
                            etiquetaMes(seleccionado.periodo, true)
                        }}</SheetTitle>
                        <SheetDescription>
                            {{
                                seleccionado.sin_datos
                                    ? 'Sin ninguna fuente de datos para este mes.'
                                    : `${pct(partePeriodo(seleccionado))} de las bajas del periodo`
                            }}
                        </SheetDescription>
                    </SheetHeader>
                    <div class="flex flex-col gap-4 px-4 pb-6">
                        <div class="flex flex-wrap gap-1.5">
                            <ChipEstado
                                v-if="seleccionado.en_curso"
                                etiqueta="Mes en curso"
                                tono="alerta"
                            />
                            <ChipEstado
                                v-if="seleccionado.corte_parcial"
                                etiqueta="Corte parcial"
                                tono="alerta"
                            />
                            <ChipEstado
                                v-if="
                                    tendencia.mes_mayor === seleccionado.periodo
                                "
                                etiqueta="Mes con más bajas"
                                tono="oro"
                            />
                            <ChipEstado
                                v-if="seleccionado.discrepancias > 0"
                                :etiqueta="`${seleccionado.discrepancias} descuadre(s) con el Excel`"
                                tono="peligro"
                                :icono="AlertTriangle"
                            />
                            <ChipEstado
                                v-for="f in seleccionado.fuentes"
                                :key="f"
                                :etiqueta="
                                    f === 'individual'
                                        ? 'Bajas individuales'
                                        : 'Total del Excel'
                                "
                                tono="neutro"
                            />
                        </div>

                        <template v-if="!seleccionado.sin_datos">
                            <p class="text-4xl font-bold tabular-nums">
                                {{ numero(seleccionado.total) }}
                                <span
                                    class="text-sm font-normal text-muted-foreground"
                                    >bajas</span
                                >
                            </p>

                            <ul class="flex flex-col gap-2.5">
                                <li
                                    v-for="c in CAPAS"
                                    :key="c"
                                    class="flex flex-col gap-1 text-sm"
                                >
                                    <div
                                        class="flex items-baseline justify-between gap-2"
                                    >
                                        <span class="flex items-center gap-1.5"
                                            ><span
                                                :class="
                                                    cn(
                                                        'size-2.5 rounded-sm',
                                                        CLASIFICACION_VISUAL[c]
                                                            .fondo,
                                                    )
                                                "
                                            />{{
                                                CLASIFICACION_VISUAL[c].etiqueta
                                            }}</span
                                        >
                                        <span class="tabular-nums"
                                            ><strong>{{
                                                numero(
                                                    valorCapa(seleccionado, c),
                                                )
                                            }}</strong>
                                            <span
                                                class="text-xs text-muted-foreground"
                                            >
                                                ·
                                                {{
                                                    pct(
                                                        porcentajeDe(
                                                            valorCapa(
                                                                seleccionado,
                                                                c,
                                                            ),
                                                            seleccionado.total,
                                                        ),
                                                    )
                                                }}</span
                                            ></span
                                        >
                                    </div>
                                    <div
                                        class="h-2 overflow-hidden rounded-full bg-muted"
                                    >
                                        <div
                                            :class="
                                                cn(
                                                    'h-full rounded-full',
                                                    CLASIFICACION_VISUAL[c]
                                                        .fondo,
                                                )
                                            "
                                            :style="{
                                                width: `${porcentajeDe(valorCapa(seleccionado, c), seleccionado.total) ?? 0}%`,
                                            }"
                                        />
                                    </div>
                                </li>
                            </ul>

                            <dl
                                class="grid grid-cols-2 gap-2 rounded-2xl bg-crema/40 p-3 text-xs"
                            >
                                <div>
                                    <dt class="text-muted-foreground">
                                        Índice del mes
                                    </dt>
                                    <dd class="text-base font-semibold">
                                        {{
                                            conDetalle
                                                ? 'No aplica'
                                                : seleccionado.indice === null
                                                  ? 'Sin datos de plantilla'
                                                  : pct(seleccionado.indice)
                                        }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-muted-foreground">
                                        Plantilla promedio
                                    </dt>
                                    <dd class="text-base font-semibold">
                                        {{
                                            seleccionado.plantilla_promedio ===
                                            null
                                                ? '—'
                                                : decimal1(
                                                      seleccionado.plantilla_promedio,
                                                  )
                                        }}
                                    </dd>
                                </div>
                                <p
                                    class="col-span-2 text-[11px] text-muted-foreground"
                                >
                                    {{ DEFINICIONES.indiceMensual }}
                                </p>
                            </dl>

                            <p
                                v-if="seleccionado.sin_desglose > 0"
                                class="rounded-xl bg-info-soft px-3 py-2 text-xs text-info"
                            >
                                {{ numero(seleccionado.sin_desglose) }} baja(s)
                                vienen del total del Excel sin desglose: no
                                tienen persona ni causal.
                            </p>
                            <p
                                v-if="seleccionado.no_desglosables > 0"
                                class="rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
                            >
                                {{ numero(seleccionado.no_desglosables) }}
                                baja(s) del Excel no se pueden desglosar con los
                                filtros de detalle.
                            </p>

                            <Button
                                class="w-full"
                                @click="
                                    nav.verHistorico({
                                        d_mes: seleccionado.periodo,
                                    })
                                "
                            >
                                Ver bajas del mes
                                <ArrowRight class="size-4" />
                            </Button>
                            <p
                                class="text-center text-[11px] text-muted-foreground"
                            >
                                En el histórico verás la causal de cada baja del
                                mes.
                            </p>
                        </template>
                    </div>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>

<style scoped>
.mes {
    cursor: pointer;
    outline: none;
}

.mes:hover .fondo-mes {
    fill: color-mix(in oklab, var(--pastel-crema) 45%, transparent);
}

.mes:focus-visible .fondo-mes {
    fill: color-mix(in oklab, var(--pastel-crema) 60%, transparent);
    stroke: var(--pastel-oro);
    stroke-width: 2;
}

.barra {
    transition: opacity 160ms ease;
}

.mes:hover .barra {
    opacity: 0.88;
}

@media (prefers-reduced-motion: reduce) {
    .barra {
        transition: none;
    }
}
</style>
