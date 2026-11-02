<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    CalendarRange,
    Gauge,
    HelpCircle,
    Hourglass,
    Lightbulb,
    LogOut,
    ShieldAlert,
    UserMinus,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import KpiRotacion from '@/components/rotacion/KpiRotacion.vue';
import MetasRotacionPanel from '@/components/rotacion/MetasRotacionPanel.vue';
import { Button } from '@/components/ui/button';
import { formatearPeriodo } from '@/lib/fechas';
import {
    DEFINICIONES,
    decimal1,
    esSeccion,
    numero,
    pct,
    plural,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import { TONO_SUAVE } from '@/lib/tonos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import type {
    AccionHallazgoRotacion,
    HallazgoRotacionTablero,
    MetasRotacionPanel as Metas,
    PermisosRotacion,
    ResumenRotacion,
} from '@/types';

/**
 * Resumen: siete indicadores (cada uno abre el histórico ya filtrado),
 * hallazgos calculados con sus acciones y el panel de metas.
 */
const props = defineProps<{
    resumen: ResumenRotacion;
    hallazgos: HallazgoRotacionTablero[];
    metas: Metas;
    puede: PermisosRotacion;
    conDetalle: boolean;
    mesFiltro: string;
}>();

const nav = useNavegacionRotacion();
const r = computed(() => props.resumen);

const periodoAnterior = computed(() =>
    r.value.comparativo
        ? formatearPeriodo(r.value.comparativo.desde, r.value.comparativo.hasta)
        : null,
);

function conSigno(n: number, sufijo = ''): string {
    return `${n > 0 ? '+' : ''}${decimal1(n)}${sufijo}`;
}

const textoDeltaTotal = computed(() => {
    const c = r.value.comparativo;

    if (!c) {
        return 'Sin periodo anterior para comparar';
    }

    return c.delta_total === 0
        ? `Igual que ${periodoAnterior.value} (${numero(c.total)})`
        : `${conSigno(c.delta_total)} vs. ${periodoAnterior.value} (${numero(c.total)})`;
});

const textoDeltaIndice = computed(() => {
    const c = r.value.comparativo;

    if (!c || c.delta_indice === null) {
        return null;
    }

    return `${conSigno(c.delta_indice, ' pts.')} vs. periodo anterior (${pct(c.indice)})`;
});

const indiceTexto = computed(() => {
    if (props.conDetalle) {
        return {
            valor: 'No aplica',
            detalle: 'Con filtros de detalle no hay plantilla comparable.',
        };
    }

    if (r.value.rotacion.indice === null) {
        return {
            valor: 'Sin datos',
            detalle: 'Sin datos de plantilla para el periodo.',
        };
    }

    return {
        valor: pct(r.value.rotacion.indice),
        detalle: `Plantilla promedio ${decimal1(r.value.rotacion.plantilla_promedio)} · ${plural(r.value.rotacion.meses_con_plantilla, 'mes', 'meses')} con plantilla`,
    };
});

const TONO_HALLAZGO: Record<HallazgoRotacionTablero['tono'], Tono> = {
    alerta: 'alerta',
    info: 'info',
    peligro: 'peligro',
    neutro: 'neutro',
};

const ICONO_HALLAZGO = {
    alerta: AlertTriangle,
    info: Lightbulb,
    peligro: ShieldAlert,
    neutro: HelpCircle,
} as const;

function ejecutar(accion: AccionHallazgoRotacion): void {
    const filtros = { ...(accion.filtros ?? {}) };
    const destino = filtros.seccion;
    delete filtros.seccion;
    const seccion = esSeccion(destino) ? destino : undefined;

    if (accion.tipo === 'seccion') {
        if (seccion) {
            nav.cambiarSeccion(seccion);
        }

        return;
    }

    nav.actualizar(filtros, { seccion });
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <EmptyState
            v-if="r.total === 0"
            :icono="Users"
            titulo="Sin bajas en el periodo"
            descripcion="No hay bajas de rotación con estos filtros. Prueba con otro periodo o quita algún filtro."
        />

        <div
            class="grid grid-cols-1 gap-3 min-[420px]:grid-cols-2 lg:grid-cols-4"
        >
            <KpiRotacion
                etiqueta="Total de bajas"
                :valor="numero(r.total)"
                :icono="UserMinus"
                tono="primario"
                :definicion="DEFINICIONES.total"
                :delta="r.comparativo?.delta_total ?? null"
                :texto-delta="textoDeltaTotal"
                :detalle="
                    r.identidad
                        ? null
                        : 'La suma por tipo no cuadra: revisa Calidad del dato.'
                "
                clicable
                texto-accion="Ver todas las bajas"
                :indice="0"
                @click="nav.verHistorico()"
            />
            <KpiRotacion
                etiqueta="Promedio mensual de bajas"
                :valor="decimal1(r.promedio_mensual)"
                :icono="CalendarRange"
                :definicion="DEFINICIONES.promedio"
                :detalle="`${plural(r.meses, 'mes considerado', 'meses considerados')}`"
                nota="Bajas por mes, no es un porcentaje."
                :indice="1"
            />
            <KpiRotacion
                etiqueta="Voluntarias"
                :valor="numero(r.voluntarias.bajas)"
                :icono="LogOut"
                tono="primario"
                :detalle="`${pct(r.voluntarias.porcentaje)} del total`"
                :definicion="DEFINICIONES.participacion"
                clicable
                :indice="2"
                @click="nav.verHistorico({ d_clasificacion: 'voluntaria' })"
            />
            <KpiRotacion
                etiqueta="Involuntarias"
                :valor="numero(r.involuntarias.bajas)"
                :icono="UserMinus"
                tono="oro"
                :detalle="`${pct(r.involuntarias.porcentaje)} del total`"
                :definicion="DEFINICIONES.participacion"
                clicable
                :indice="3"
                @click="nav.verHistorico({ d_clasificacion: 'involuntaria' })"
            />
            <KpiRotacion
                etiqueta="Sin clasificar"
                :valor="numero(r.sin_clasificar.bajas)"
                :icono="HelpCircle"
                :tono="r.sin_clasificar.bajas > 0 ? 'alerta' : 'neutro'"
                :detalle="`${pct(r.sin_clasificar.porcentaje)} del total`"
                :definicion="DEFINICIONES.sinClasificar"
                :nota="
                    r.sin_clasificar.sin_desglose > 0
                        ? `${numero(r.sin_clasificar.sin_desglose)} sin desglose del Excel · ${numero(r.sin_clasificar.por_revisar)} se pueden revisar una por una`
                        : null
                "
                :indice="4"
            >
                <template v-if="r.sin_clasificar.por_revisar > 0" #accion>
                    <Button
                        size="sm"
                        variant="outline"
                        class="w-full"
                        @click="nav.verHistorico({ rapido: 'pendientes' })"
                    >
                        Revisar pendientes
                        <ArrowRight class="size-3.5" />
                    </Button>
                </template>
            </KpiRotacion>
            <KpiRotacion
                etiqueta="Rotación temprana"
                :valor="pct(r.temprana.porcentaje)"
                :icono="Hourglass"
                :tono="r.temprana.porcentaje !== null ? 'alerta' : 'neutro'"
                :atenuado="r.temprana.porcentaje === null"
                :definicion="DEFINICIONES.temprana"
                :detalle="
                    r.temprana.base > 0
                        ? `${numero(r.temprana.bajas)} de ${numero(r.temprana.base)} con fecha de ingreso válida`
                        : 'Sin bajas con fecha de ingreso válida'
                "
                :nota="
                    r.temprana.excluidas > 0
                        ? `${plural(r.temprana.excluidas, 'baja excluida', 'bajas excluidas')} por no tener fecha de ingreso válida`
                        : null
                "
                clicable
                texto-accion="Ver bajas tempranas"
                :indice="5"
                @click="nav.verHistorico({ rapido: 'temprana' })"
            />
            <KpiRotacion
                etiqueta="Índice de rotación del periodo"
                :valor="indiceTexto.valor"
                :icono="Gauge"
                tono="info"
                :atenuado="conDetalle || r.rotacion.indice === null"
                :definicion="`${DEFINICIONES.indice} Plantilla promedio = ${DEFINICIONES.plantilla}`"
                :detalle="indiceTexto.detalle"
                :delta="r.comparativo?.delta_indice ?? null"
                :texto-delta="conDetalle ? null : textoDeltaIndice"
                :nota="
                    !conDetalle && r.rotacion.promedio_indices !== null
                        ? `Promedio de índices mensuales: ${pct(r.rotacion.promedio_indices)}`
                        : null
                "
                :indice="6"
            />
        </div>

        <div
            class="grid items-start gap-5 xl:grid-cols-[minmax(0,3fr)_minmax(20rem,2fr)]"
        >
            <section
                class="flex min-w-0 flex-col gap-3"
                aria-labelledby="titulo-hallazgos"
            >
                <h2 id="titulo-hallazgos" class="text-sm font-semibold">
                    Hallazgos
                    <span class="font-normal text-muted-foreground"
                        >· patrones para investigar, no conclusiones</span
                    >
                </h2>
                <p
                    v-if="!hallazgos.length"
                    class="rounded-2xl bg-crema/30 px-4 py-6 text-center text-sm text-muted-foreground"
                >
                    No hay hallazgos que destacar con estos filtros.
                </p>
                <ul v-else class="grid gap-2.5">
                    <li
                        v-for="(h, i) in hallazgos"
                        :key="h.clave"
                        class="flex aparece-escalonado gap-3 rounded-2xl border border-border/60 bg-card p-3.5 shadow-sm"
                        :style="{ '--i': i }"
                    >
                        <span
                            :class="
                                cn(
                                    'grid size-9 shrink-0 place-items-center rounded-xl',
                                    TONO_SUAVE[TONO_HALLAZGO[h.tono]],
                                )
                            "
                            aria-hidden="true"
                        >
                            <component
                                :is="ICONO_HALLAZGO[h.tono]"
                                class="size-4"
                            />
                        </span>
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p class="text-sm font-medium">{{ h.titulo }}</p>
                            <p class="text-xs text-muted-foreground">
                                {{ h.detalle }}
                            </p>
                            <div
                                v-if="h.acciones.length"
                                class="mt-1 flex flex-wrap gap-2"
                            >
                                <template
                                    v-for="a in h.acciones"
                                    :key="a.etiqueta"
                                >
                                    <Button
                                        v-if="a.tipo === 'enlace' && a.href"
                                        size="sm"
                                        variant="outline"
                                        as-child
                                    >
                                        <Link :href="a.href"
                                            >{{ a.etiqueta
                                            }}<ArrowRight class="size-3.5"
                                        /></Link>
                                    </Button>
                                    <Button
                                        v-else
                                        size="sm"
                                        variant="outline"
                                        @click="ejecutar(a)"
                                        >{{ a.etiqueta
                                        }}<ArrowRight class="size-3.5"
                                    /></Button>
                                </template>
                            </div>
                        </div>
                    </li>
                </ul>
            </section>

            <MetasRotacionPanel
                :metas="metas"
                :puede-editar="puede.editar_metas"
                :mes-inicial="mesFiltro"
            />
        </div>
    </div>
</template>
