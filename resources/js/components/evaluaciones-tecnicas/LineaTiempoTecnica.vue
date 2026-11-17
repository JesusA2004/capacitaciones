<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Flag, LogIn, Trophy } from '@lucide/vue';
import { computed } from 'vue';
import ChipEstadoTecnica from '@/components/evaluaciones-tecnicas/ChipEstadoTecnica.vue';
import { ESTADO_TECNICA, textoPlazo } from '@/lib/evaluacionesTecnicas';
import { formatearFecha } from '@/lib/fechas';
import { TONO_SUAVE } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import type { FilaEvaluacionTecnica } from '@/types';

/**
 * Línea de tiempo «Ingreso → Seguimiento 15 → 30 → 60 · técnica final»: la
 * barra se llena hasta HOY (días desde el ingreso) y cada hito muestra su
 * estado, fecha y calificación. Horizontal en escritorio, vertical en
 * teléfono. `hrefDe` decide a dónde lleva cada hito (resultado, contestar).
 */
const props = withDefaults(
    defineProps<{
        hitos: FilaEvaluacionTecnica[];
        fechaIngreso: string | null;
        diasDesdeIngreso: number | null;
        hrefDe?: (hito: FilaEvaluacionTecnica) => string | null;
    }>(),
    { hrefDe: undefined },
);

const ultimoDia = computed(() =>
    Math.max(1, ...props.hitos.map((h) => h.dias)),
);
/** Avance de la barra hasta hoy (0-100). */
const avance = computed(() =>
    props.diasDesdeIngreso === null
        ? 0
        : Math.min(100, (props.diasDesdeIngreso / ultimoDia.value) * 100),
);

function posicion(dias: number): number {
    return (dias / ultimoDia.value) * 100;
}

function enlace(hito: FilaEvaluacionTecnica): string | null {
    return props.hrefDe ? props.hrefDe(hito) : null;
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <!-- Escritorio: horizontal -->
        <div class="relative hidden px-6 pt-10 pb-2 md:block">
            <div class="relative h-2 rounded-full bg-muted">
                <div
                    class="absolute inset-y-0 left-0 crece-barra rounded-full bg-gradient-to-r from-[var(--pastel-esmeralda)] to-[var(--pastel-oro)]"
                    :style="{ width: `${avance}%` }"
                />
                <!-- Hoy -->
                <div
                    v-if="diasDesdeIngreso !== null && avance < 100"
                    class="absolute -top-8 flex -translate-x-1/2 flex-col items-center gap-1"
                    :style="{ left: `${avance}%` }"
                >
                    <span
                        class="rounded-full bg-primary px-2 py-0.5 text-[10px] font-semibold text-primary-foreground shadow-sm"
                        >Hoy · día {{ diasDesdeIngreso }}</span
                    >
                    <span class="h-4 w-0.5 rounded bg-primary" />
                </div>
                <!-- Ingreso -->
                <span
                    class="absolute top-1/2 left-0 flex size-7 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-card text-primary shadow ring-2 ring-[var(--pastel-esmeralda)]"
                    title="Ingreso"
                >
                    <LogIn class="size-3.5" />
                </span>
                <span
                    v-for="h in hitos"
                    :key="`n-${h.dias}`"
                    :class="
                        cn(
                            'absolute top-1/2 flex size-9 -translate-x-1/2 -translate-y-1/2 aparece-zoom items-center justify-center rounded-full shadow ring-4 ring-card',
                            TONO_SUAVE[ESTADO_TECNICA[h.estado].tono],
                        )
                    "
                    :style="{ left: `${posicion(h.dias)}%` }"
                >
                    <component
                        :is="h.es_final ? Trophy : ESTADO_TECNICA[h.estado].icono"
                        class="size-4"
                    />
                </span>
            </div>
            <div class="relative mt-4 h-5 text-[11px] text-muted-foreground">
                <span class="absolute left-0 -translate-x-1/2">Día 0</span>
                <span
                    v-for="h in hitos"
                    :key="`d-${h.dias}`"
                    class="absolute -translate-x-1/2 font-medium tabular-nums"
                    :style="{ left: `${posicion(h.dias)}%` }"
                    >Día {{ h.dias }}</span
                >
            </div>
        </div>

        <!-- Tarjetas de cada hito (vertical en teléfono) -->
        <ol
            class="relative flex entrada-escalonada flex-col gap-3 md:grid md:gap-4"
            :style="{
                gridTemplateColumns: `repeat(${Math.max(1, hitos.length)}, minmax(0, 1fr))`,
            }"
        >
            <li
                v-for="h in hitos"
                :key="`c-${h.dias}`"
                class="relative pl-9 md:pl-0"
            >
                <span
                    class="absolute top-0 bottom-0 left-3.5 w-0.5 bg-border md:hidden"
                    aria-hidden="true"
                />
                <span
                    :class="
                        cn(
                            'absolute top-4 left-0 flex size-7 items-center justify-center rounded-full ring-4 ring-background md:hidden',
                            TONO_SUAVE[ESTADO_TECNICA[h.estado].tono],
                        )
                    "
                    aria-hidden="true"
                >
                    <component
                        :is="h.es_final ? Trophy : ESTADO_TECNICA[h.estado].icono"
                        class="size-3.5"
                    />
                </span>
                <component
                    :is="enlace(h) ? Link : 'div'"
                    :href="enlace(h) ?? undefined"
                    :class="
                        cn(
                            'flex h-full flex-col gap-2 rounded-2xl border border-border bg-card p-4 shadow-xs',
                            enlace(h) && 'tarjeta-interactiva',
                            h.estado === 'vencida' && 'border-destructive/40',
                        )
                    "
                >
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ h.nombre }}</p>
                            <p class="text-xs text-muted-foreground">
                                Día {{ h.dias }}
                                <template v-if="h.es_final">
                                    ·
                                    <span class="inline-flex items-center gap-1 font-medium text-bronce"
                                        ><Flag class="size-3" /> Técnica final</span
                                    >
                                </template>
                            </p>
                        </div>
                        <span
                            v-if="h.calificacion !== null"
                            :class="
                                cn(
                                    'shrink-0 text-xl font-semibold tabular-nums',
                                    h.aprobada === false
                                        ? 'text-warning'
                                        : 'text-success',
                                )
                            "
                            >{{ h.calificacion }}</span
                        >
                    </div>
                    <ChipEstadoTecnica :estado="h.estado" />
                    <dl class="mt-auto grid grid-cols-2 gap-x-2 gap-y-1 text-xs">
                        <dt class="text-muted-foreground">Fecha</dt>
                        <dd class="text-right tabular-nums">
                            {{ formatearFecha(h.fecha_programada) }}
                        </dd>
                        <dt class="text-muted-foreground">Contesta</dt>
                        <dd class="text-right">{{ h.contesta_etiqueta }}</dd>
                        <dt class="text-muted-foreground">Mínima</dt>
                        <dd class="text-right tabular-nums">
                            {{ h.calificacion_minima }}
                        </dd>
                    </dl>
                    <p
                        v-if="h.estado === 'pendiente' || h.estado === 'vencida'"
                        :class="
                            cn(
                                'text-xs font-medium',
                                h.estado === 'vencida'
                                    ? 'text-destructive'
                                    : 'text-info',
                            )
                        "
                    >
                        {{ textoPlazo(h.dias_restantes) }}
                    </p>
                    <p
                        v-else-if="h.estado === 'programada'"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            h.dias_restantes > 0
                                ? `Se abre en ${h.dias_restantes} ${h.dias_restantes === 1 ? 'día' : 'días'}`
                                : 'Se abre hoy'
                        }}
                    </p>
                </component>
            </li>
        </ol>
        <p v-if="fechaIngreso" class="text-xs text-muted-foreground">
            Fecha de ingreso: {{ formatearFecha(fechaIngreso) }}. Cada día del
            calendario se cuenta desde ahí.
        </p>
    </div>
</template>
