<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleSlash,
    MessageSquareText,
    PencilLine,
    Route,
    XCircle,
} from '@lucide/vue';
import { computed } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import AnilloCalificacion from '@/components/evaluaciones-tecnicas/AnilloCalificacion.vue';
import AvatarPersona from '@/components/evaluaciones-tecnicas/AvatarPersona.vue';
import ChipEstadoTecnica from '@/components/evaluaciones-tecnicas/ChipEstadoTecnica.vue';
import { Button } from '@/components/ui/button';
import { textoPlazo } from '@/lib/evaluacionesTecnicas';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    colaborador as verColaborador,
    contestar,
    index,
} from '@/routes/evaluaciones-tecnicas';
import type { DetalleEvaluacionTecnica } from '@/types';

const props = defineProps<{
    evaluacion: DetalleEvaluacionTecnica;
    puedeContestar: boolean;
    puedeVerLinea: boolean;
}>();

defineOptions({
    layout: (p: { evaluacion?: DetalleEvaluacionTecnica }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Evaluaciones técnicas', href: index() },
            { title: p.evaluacion?.nombre ?? 'Resultado', href: '' },
        ],
    }),
});

const e = computed(() => props.evaluacion);
const contestada = computed(() =>
    ['aprobada', 'no_aprobada', 'contestada'].includes(e.value.estado),
);
const aciertos = computed(
    () => e.value.preguntas.filter((p) => p.acierto === true).length,
);
const calificables = computed(
    () => e.value.preguntas.filter((p) => p.acierto !== null).length,
);
</script>

<template>
    <Head :title="`${evaluacion.nombre} · ${evaluacion.colaborador?.nombre ?? ''}`" />

    <div class="pagina-ancha flex flex-col gap-5 pb-10">
        <CrudPageHeader :titulo="evaluacion.nombre" :descripcion="evaluacion.colaborador?.nombre" detalle>
            <Button v-if="puedeVerLinea && evaluacion.colaborador" variant="outline" as-child>
                <Link :href="verColaborador(evaluacion.colaborador.id)">
                    <Route class="size-4" /> Línea de tiempo
                </Link>
            </Button>
            <Button v-if="puedeContestar && evaluacion.id" as-child>
                <Link :href="contestar(evaluacion.id)">
                    <PencilLine class="size-4" /> Contestar ahora
                </Link>
            </Button>
        </CrudPageHeader>

        <section
            class="grid entrada-suave gap-5 rounded-3xl border border-border bg-gradient-to-br from-salvia/50 via-card to-crema/50 p-6 shadow-xs lg:grid-cols-[auto_minmax(0,1fr)_auto] lg:items-center"
        >
            <div class="flex items-center gap-4">
                <AvatarPersona :nombre="evaluacion.colaborador?.nombre ?? ''" :foto-url="evaluacion.colaborador?.foto_url" tamano="lg" />
                <div class="min-w-0">
                    <p class="font-display text-xl font-semibold">{{ evaluacion.colaborador?.nombre }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{ evaluacion.puesto }}<template v-if="evaluacion.colaborador?.sucursal"> · {{ evaluacion.colaborador.sucursal }}</template>
                    </p>
                </div>
            </div>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div>
                    <dt class="text-xs text-muted-foreground">Evaluación</dt>
                    <dd class="font-medium">{{ evaluacion.nombre }} · día {{ evaluacion.dias }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Contesta</dt>
                    <dd class="font-medium">
                        {{ evaluacion.contesta_etiqueta }}
                        <span v-if="evaluacion.evaluador && evaluacion.contesta === 'jefe_directo'" class="block text-xs font-normal text-muted-foreground">{{ evaluacion.evaluador }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Fecha límite</dt>
                    <dd class="font-medium tabular-nums">{{ formatearFecha(evaluacion.fecha_limite) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Estado</dt>
                    <dd><ChipEstadoTecnica :estado="evaluacion.estado" /></dd>
                </div>
            </dl>
            <AnilloCalificacion :calificacion="evaluacion.calificacion" :minima="evaluacion.calificacion_minima" />
        </section>

        <!-- Pendiente / cancelada -->
        <section
            v-if="!contestada"
            class="flex items-start gap-3 rounded-2xl border border-border bg-card p-5 text-sm shadow-xs"
        >
            <CircleSlash v-if="evaluacion.estado === 'cancelada'" class="mt-0.5 size-5 shrink-0 text-muted-foreground" />
            <PencilLine v-else class="mt-0.5 size-5 shrink-0 text-info" />
            <div>
                <p class="font-medium">
                    {{ evaluacion.estado === 'cancelada' ? 'Evaluación cancelada' : 'Aún no se contesta' }}
                </p>
                <p class="text-muted-foreground">
                    <template v-if="evaluacion.estado === 'cancelada'">{{ evaluacion.motivo_cancelacion }} Se conserva en su historial.</template>
                    <template v-else>{{ textoPlazo(evaluacion.dias_restantes) }}. Se avisó a quien la contesta.</template>
                </p>
            </div>
        </section>

        <template v-else>
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-border bg-card p-4 shadow-xs">
                    <p class="text-xs text-muted-foreground">Puntos</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ evaluacion.puntos_obtenidos ?? '—' }}<span class="text-base text-muted-foreground"> / {{ evaluacion.puntos_totales ?? '—' }}</span>
                    </p>
                </div>
                <div class="rounded-2xl border border-border bg-card p-4 shadow-xs">
                    <p class="text-xs text-muted-foreground">Respuestas correctas</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        <template v-if="calificables">{{ aciertos }}<span class="text-base text-muted-foreground"> / {{ calificables }}</span></template>
                        <template v-else>—</template>
                    </p>
                </div>
                <div class="rounded-2xl border border-border bg-card p-4 shadow-xs">
                    <p class="text-xs text-muted-foreground">Contestada</p>
                    <p class="text-sm font-medium">{{ formatearFechaHora(evaluacion.contestada_en ?? null) }}</p>
                    <p v-if="evaluacion.contestada_por" class="text-xs text-muted-foreground">por {{ evaluacion.contestada_por }}</p>
                </div>
            </div>

            <section
                v-if="evaluacion.comentarios"
                class="flex gap-3 rounded-2xl border border-border bg-crema/40 p-5 text-sm"
            >
                <MessageSquareText class="mt-0.5 size-5 shrink-0 text-bronce" />
                <p class="whitespace-pre-line">{{ evaluacion.comentarios }}</p>
            </section>

            <section v-if="evaluacion.preguntas.length" class="flex flex-col gap-2">
                <h2 class="px-1 text-sm font-semibold">Respuesta por pregunta</h2>
                <ol class="flex entrada-escalonada flex-col gap-2">
                    <li
                        v-for="(p, i) in evaluacion.preguntas"
                        :key="i"
                        :class="
                            cn(
                                'flex gap-3 rounded-2xl border bg-card p-4 shadow-xs',
                                p.acierto === false ? 'border-warning/50' : 'border-border',
                            )
                        "
                    >
                        <CheckCircle2 v-if="p.acierto === true" class="mt-0.5 size-5 shrink-0 text-success" />
                        <XCircle v-else-if="p.acierto === false" class="mt-0.5 size-5 shrink-0 text-warning" />
                        <span v-else class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-[10px] font-semibold">{{ i + 1 }}</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-muted-foreground">{{ p.seccion }}</p>
                            <p class="font-medium text-pretty">{{ p.enunciado }}</p>
                            <p class="mt-1 text-sm">
                                <span class="text-muted-foreground">Respuesta:</span>
                                {{ p.respuesta ?? 'Sin respuesta' }}
                            </p>
                            <p v-if="p.acierto === false && p.correcta" class="text-sm text-success">
                                <span class="text-muted-foreground">Correcta:</span> {{ p.correcta }}
                            </p>
                        </div>
                        <span v-if="p.maximos" class="shrink-0 text-sm font-medium tabular-nums">{{ p.obtenidos }}/{{ p.maximos }}</span>
                    </li>
                </ol>
            </section>
        </template>
    </div>
</template>
