<script setup lang="ts">
import {
    ArrowRight,
    Building,
    Hourglass,
    Info,
    ListOrdered,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    CLASIFICACION_VISUAL,
    DEFINICIONES,
    diasYMeses,
    numero,
    pct,
    plural,
    tonoClasificacion,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import { cn } from '@/lib/utils';
import type {
    AntiguedadRotacion,
    CausalesRotacionTablero,
    CausalHomologadaRotacion,
} from '@/types';

/**
 * Causales homologadas (de mayor a menor) y antigüedad al salir. Cada barra
 * abre el histórico con ese desglose; las bajas del Excel sin desglose se
 * informan aparte, nunca como una causal.
 */
const props = defineProps<{
    causales: CausalesRotacionTablero;
    antiguedad: AntiguedadRotacion;
}>();

const nav = useNavegacionRotacion();

const maximo = computed(() =>
    Math.max(1, ...props.causales.causales.map((c) => c.bajas)),
);
const maximoRango = computed(() =>
    Math.max(1, ...props.antiguedad.rangos.map((r) => r.bajas)),
);

function abrirCausal(c: CausalHomologadaRotacion): void {
    nav.verHistorico({ d_causal: c.id ?? 'pendiente' });
}

const indicadoresAntiguedad = computed(() => {
    const a = props.antiguedad;
    const base = a.base;
    const parte = (n: number) =>
        base > 0 ? `${Math.round((n * 1000) / base) / 10} %` : '—';

    return [
        {
            etiqueta: 'Antes de 90 días',
            valor: numero(a.antes_90_dias),
            detalle: parte(a.antes_90_dias),
        },
        {
            etiqueta: 'Antes de 6 meses',
            valor: numero(a.antes_6_meses),
            detalle: parte(a.antes_6_meses),
        },
        {
            etiqueta: 'En el primer año',
            valor: numero(a.primer_anio),
            detalle: parte(a.primer_anio),
        },
    ];
});
</script>

<template>
    <div
        class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(20rem,2fr)]"
    >
        <section
            class="flex min-w-0 flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            aria-labelledby="titulo-causales"
        >
            <div>
                <h2 id="titulo-causales" class="font-semibold">
                    Causales homologadas
                </h2>
                <p class="text-xs text-muted-foreground">
                    {{
                        plural(
                            causales.base,
                            'baja individual',
                            'bajas individuales',
                        )
                    }}
                    con causal. Toca una causal para ver a las personas.
                </p>
            </div>

            <EmptyState
                v-if="!causales.causales.length"
                :icono="ListOrdered"
                titulo="Sin bajas individuales"
                descripcion="En este periodo solo hay totales del Excel, que no traen causal."
            />

            <ul v-else class="flex flex-col gap-1">
                <li
                    v-for="(c, i) in causales.causales"
                    :key="c.clave + (c.id ?? '')"
                    class="aparece-escalonado"
                    :style="{ '--i': i }"
                >
                    <button
                        type="button"
                        class="causal group flex w-full flex-col gap-1.5 rounded-2xl px-3 py-2.5 text-left transition-colors hover:bg-crema/40 focus-visible:outline-2 focus-visible:outline-oro"
                        :aria-label="`${c.nombre}: ${c.bajas} bajas, ${pct(c.porcentaje)}. Ver colaboradores`"
                        @click="abrirCausal(c)"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                        >
                            <span
                                class="flex min-w-0 flex-wrap items-center gap-1.5 text-sm font-medium"
                            >
                                {{ c.nombre }}
                                <ChipEstado
                                    :etiqueta="c.clasificacion_etiqueta"
                                    :tono="tonoClasificacion(c.clasificacion)"
                                />
                                <ChipEstado
                                    v-if="c.es_estructural"
                                    etiqueta="Estructural"
                                    tono="info"
                                    :icono="Building"
                                />
                            </span>
                            <span class="shrink-0 text-sm tabular-nums"
                                ><strong>{{ numero(c.bajas) }}</strong>
                                <span class="text-muted-foreground">
                                    · {{ pct(c.porcentaje) }}</span
                                ></span
                            >
                        </div>
                        <div class="flex items-center gap-2">
                            <div
                                class="h-2.5 flex-1 overflow-hidden rounded-full bg-muted"
                            >
                                <div
                                    :class="
                                        cn(
                                            'barra-causal h-full rounded-full',
                                            c.clasificacion
                                                ? CLASIFICACION_VISUAL[
                                                      c.clasificacion
                                                  ].fondo
                                                : 'bg-muted-foreground/40',
                                        )
                                    "
                                    :style="{
                                        width: `${(c.bajas / maximo) * 100}%`,
                                    }"
                                />
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                                >Ver colaboradores<ArrowRight class="size-3"
                            /></span>
                        </div>
                        <span class="text-[11px] text-muted-foreground">{{
                            plural(c.sucursales, 'sucursal', 'sucursales')
                        }}</span>
                    </button>
                </li>
            </ul>

            <p
                v-if="causales.sin_desglose > 0"
                class="flex items-start gap-2 rounded-xl bg-info-soft px-3 py-2 text-xs text-info"
            >
                <Info class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                Además, {{ plural(causales.sin_desglose, 'baja', 'bajas') }} del
                histórico agregado del Excel no traen causal: no están en esta
                lista ni se cuentan como una causal.
            </p>
        </section>

        <section
            class="flex min-w-0 flex-col gap-4 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            aria-labelledby="titulo-antiguedad"
        >
            <div class="flex items-start justify-between gap-2">
                <div>
                    <h2
                        id="titulo-antiguedad"
                        class="flex items-center gap-2 font-semibold"
                    >
                        <Hourglass
                            class="size-4 text-bronce"
                            aria-hidden="true"
                        />
                        Antigüedad al salir
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Desde la fecha de ingreso de esa relación laboral hasta
                        el último día laborado.
                    </p>
                </div>
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="grid size-7 shrink-0 place-items-center rounded-full text-muted-foreground hover:bg-crema/60"
                            :aria-label="DEFINICIONES.temprana"
                        >
                            <Info class="size-4" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent class="max-w-64">
                        Rotación temprana: {{ DEFINICIONES.temprana }}
                    </TooltipContent>
                </Tooltip>
            </div>

            <dl class="grid grid-cols-3 gap-2">
                <div
                    v-for="ind in indicadoresAntiguedad"
                    :key="ind.etiqueta"
                    class="rounded-2xl bg-crema/40 p-2.5"
                >
                    <dt class="text-[11px] leading-tight text-muted-foreground">
                        {{ ind.etiqueta }}
                    </dt>
                    <dd class="text-xl font-bold tabular-nums">
                        {{ ind.valor }}
                    </dd>
                    <dd class="text-[11px] text-muted-foreground tabular-nums">
                        {{ ind.detalle }}
                    </dd>
                </div>
            </dl>

            <ul class="flex flex-col gap-1">
                <li v-for="r in antiguedad.rangos" :key="r.clave">
                    <button
                        type="button"
                        class="flex w-full flex-col gap-1 rounded-xl px-2 py-1.5 text-left text-xs transition-colors hover:bg-crema/40 focus-visible:outline-2 focus-visible:outline-oro disabled:cursor-default disabled:hover:bg-transparent"
                        :disabled="r.bajas === 0"
                        :aria-label="`${r.etiqueta}: ${r.bajas} bajas. Ver colaboradores`"
                        @click="nav.verHistorico({ d_rango: r.clave })"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <span
                                :class="
                                    r.clave === 'sin_fecha'
                                        ? 'text-muted-foreground italic'
                                        : ''
                                "
                                >{{ r.etiqueta }}
                                <span
                                    v-if="r.temprana"
                                    class="ml-1 rounded-full bg-warning-soft px-1.5 text-[10px] text-warning"
                                    >temprana</span
                                ></span
                            >
                            <span class="tabular-nums"
                                ><strong>{{ numero(r.bajas) }}</strong>
                                <span
                                    v-if="r.porcentaje !== null"
                                    class="text-muted-foreground"
                                >
                                    · {{ pct(r.porcentaje) }}</span
                                ></span
                            >
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                :class="
                                    cn(
                                        'h-full rounded-full',
                                        r.clave === 'sin_fecha'
                                            ? 'bg-muted-foreground/30'
                                            : r.temprana
                                              ? 'bg-chart-2'
                                              : 'bg-chart-3',
                                    )
                                "
                                :style="{
                                    width: `${(r.bajas / maximoRango) * 100}%`,
                                }"
                            />
                        </div>
                    </button>
                </li>
            </ul>

            <dl class="grid grid-cols-2 gap-2 text-xs">
                <div class="rounded-2xl border border-border/60 p-2.5">
                    <dt class="text-muted-foreground">Promedio</dt>
                    <dd class="font-semibold">
                        {{ diasYMeses(antiguedad.promedio_dias) }}
                    </dd>
                </div>
                <div class="rounded-2xl border border-border/60 p-2.5">
                    <dt class="text-muted-foreground">Mediana</dt>
                    <dd class="font-semibold">
                        {{ diasYMeses(antiguedad.mediana_dias) }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="antiguedad.excluidas > 0 || antiguedad.inconsistentes > 0"
                class="rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
            >
                Base: {{ numero(antiguedad.base) }} con fecha de ingreso válida.
                <template v-if="antiguedad.excluidas > 0">
                    {{
                        plural(
                            antiguedad.excluidas,
                            'baja excluida',
                            'bajas excluidas',
                        )
                    }}
                    por no tener fecha válida</template
                ><template v-if="antiguedad.inconsistentes > 0">
                    ({{ numero(antiguedad.inconsistentes) }} con baja anterior
                    al ingreso)</template
                >.
                <button
                    type="button"
                    class="ml-1 font-medium underline underline-offset-2"
                    @click="nav.verHistorico({ rapido: 'sin_fecha' })"
                >
                    Revisar
                </button>
            </p>
            <p
                v-if="antiguedad.sin_desglose > 0"
                class="text-[11px] text-muted-foreground"
            >
                {{ plural(antiguedad.sin_desglose, 'baja', 'bajas') }} del Excel
                sin desglose no tienen antigüedad.
            </p>
        </section>
    </div>
</template>

<style scoped>
.barra-causal {
    transition: width 320ms cubic-bezier(0.2, 0.8, 0.2, 1);
}

@media (prefers-reduced-motion: reduce) {
    .barra-causal {
        transition: none;
    }
}
</style>
