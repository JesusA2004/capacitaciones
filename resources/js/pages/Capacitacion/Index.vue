<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    Award,
    BookOpen,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    GraduationCap,
    Hourglass,
    Plus,
    Send,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AnilloProgreso from '@/components/Capacitacion/AnilloProgreso.vue';
import PortadaCurso from '@/components/Capacitacion/PortadaCurso.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { create as nuevaAsignacion } from '@/routes/asignaciones';
import { index as indexCalificaciones } from '@/routes/calificaciones/cuestionarios';
import {
    certificacion as rutaCertificacion,
    index as indexCapacitacion,
} from '@/routes/capacitacion';
import { aula, index as indexCursos } from '@/routes/cursos';
import type { PermisosPortadaCapacitacion, PortadaCapacitacion } from '@/types';

const props = defineProps<{
    portada?: PortadaCapacitacion;
    puede: PermisosPortadaCapacitacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
        ],
    },
});

const serie = computed(() => props.portada?.serie ?? []);
const estados = computed(() => props.portada?.estados ?? []);

function porcentaje(completaron: number, inscritos: number): number {
    return inscritos === 0 ? 0 : Math.round((100 * completaron) / inscritos);
}
</script>

<template>
    <Head title="Capacitación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <!-- Encabezado tipo «aula»: saludo, propósito y accesos rápidos. -->
        <section
            class="relative entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-linear-to-br from-salvia via-menta to-crema p-6 shadow-sm sm:p-8"
        >
            <span
                class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-card/30"
                aria-hidden="true"
            />
            <span
                class="pointer-events-none absolute -bottom-20 left-1/3 size-48 rounded-full bg-oro/10"
                aria-hidden="true"
            />
            <div
                class="relative flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between"
            >
                <div class="max-w-2xl">
                    <p
                        class="mb-2 inline-flex items-center gap-2 rounded-full bg-card/70 px-3 py-1 text-xs font-semibold tracking-wide text-bronce uppercase"
                    >
                        <GraduationCap class="size-3.5" />
                        Capacitación Mr. Lana
                    </p>
                    <Heading
                        title="Capacitación"
                        description="Aulas, trabajo asignado, calificaciones y la Certificación Mr. Lana en un solo lugar."
                    />
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="puede.ver_cursos" as-child>
                        <Link :href="indexCursos()">
                            <Plus v-if="puede.crear_curso" class="size-4" />
                            <BookOpen v-else class="size-4" />
                            {{
                                puede.crear_curso
                                    ? 'Crear o editar cursos'
                                    : 'Ver cursos'
                            }}
                        </Link>
                    </Button>
                    <Button v-if="puede.asignar" variant="secondary" as-child>
                        <Link :href="nuevaAsignacion()">
                            <Send class="size-4" />
                            Asignar curso
                        </Link>
                    </Button>
                    <Button
                        v-if="puede.certificacion"
                        variant="outline"
                        class="bg-card/70"
                        as-child
                    >
                        <Link :href="rutaCertificacion()">
                            <Award class="size-4" />
                            Certificación
                        </Link>
                    </Button>
                </div>
            </div>
        </section>

        <Deferred data="portada">
            <template #fallback>
                <div class="grid gap-4" aria-busy="true">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
                        <Skeleton
                            v-for="n in 5"
                            :key="n"
                            class="h-24 rounded-2xl"
                        />
                    </div>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <Skeleton class="h-64 rounded-2xl lg:col-span-2" />
                        <Skeleton class="h-64 rounded-2xl" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Skeleton
                            v-for="n in 4"
                            :key="n"
                            class="h-56 rounded-2xl"
                        />
                    </div>
                </div>
            </template>

            <template v-if="portada">
                <div
                    class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-5"
                >
                    <MetricCard
                        etiqueta="Cursos publicados"
                        :valor="portada.indicadores.cursos_publicados"
                        :icono="BookOpen"
                        color-clase="bg-salvia text-primary"
                    />
                    <MetricCard
                        etiqueta="Participantes"
                        :valor="portada.indicadores.participantes"
                        :icono="Users"
                        color-clase="bg-menta text-esmeralda"
                    />
                    <MetricCard
                        etiqueta="Tasa de finalización"
                        :valor="`${portada.indicadores.tasa_finalizacion}%`"
                        :icono="CheckCircle2"
                        color-clase="bg-success-soft text-primary"
                    />
                    <MetricCard
                        etiqueta="Por calificar"
                        :valor="portada.indicadores.por_calificar"
                        :icono="ClipboardCheck"
                        color-clase="bg-warning-soft text-bronce"
                    />
                    <MetricCard
                        etiqueta="Constancias emitidas"
                        :valor="portada.indicadores.constancias"
                        :icono="Award"
                        color-clase="bg-oro/20 text-bronce"
                    />
                </div>

                <div class="grid gap-4 lg:grid-cols-3">
                    <div class="lg:col-span-2">
                        <DashboardChartCard
                            title="Cursos terminados por mes"
                            description="Últimos seis meses, en tu alcance."
                            type="bar"
                            :data="serie"
                            x-key="etiqueta"
                            y-key="completadas"
                            empty-title="Aún no hay cursos terminados"
                            empty-description="Cuando alguien termine un curso aparecerá aquí."
                        />
                    </div>
                    <DashboardChartCard
                        title="Avance de inscripciones"
                        description="Pendientes, en progreso y completadas."
                        type="donut"
                        :data="estados"
                        label-key="etiqueta"
                        value-key="total"
                        :colors="[
                            'var(--pastel-crema-2)',
                            'var(--pastel-oro)',
                            'var(--pastel-esmeralda)',
                        ]"
                        empty-title="Sin inscripciones"
                        empty-description="Asigna un curso para empezar."
                    />
                </div>

                <div
                    v-if="
                        portada.indicadores.por_calificar > 0 ||
                        portada.indicadores.vencen_pronto > 0
                    "
                    class="flex flex-wrap gap-3"
                >
                    <Link
                        v-if="
                            puede.calificar &&
                            portada.indicadores.por_calificar > 0
                        "
                        :href="indexCalificaciones()"
                        class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/60 bg-warning-soft/60 px-4 py-3 text-sm"
                    >
                        <ClipboardCheck class="size-5 text-bronce" />
                        <span>
                            <strong class="tabular-nums">{{
                                portada.indicadores.por_calificar
                            }}</strong>
                            entregas esperan calificación
                        </span>
                    </Link>
                    <div
                        v-if="portada.indicadores.vencen_pronto > 0"
                        class="flex items-center gap-3 rounded-2xl border border-border/60 bg-danger-soft/50 px-4 py-3 text-sm"
                    >
                        <Hourglass class="size-5 text-destructive" />
                        <span>
                            <strong class="tabular-nums">{{
                                portada.indicadores.vencen_pronto
                            }}</strong>
                            asignaciones vencen en los próximos días
                        </span>
                    </div>
                </div>

                <section class="flex flex-col gap-3">
                    <div class="flex items-center justify-between">
                        <h2 class="text-lg font-semibold">Aulas</h2>
                        <Link
                            v-if="puede.ver_cursos"
                            :href="indexCursos()"
                            class="text-sm font-medium text-primary hover:underline"
                            >Ver todos los cursos</Link
                        >
                    </div>

                    <EmptyState
                        v-if="portada.cursos.length === 0"
                        :icono="GraduationCap"
                        titulo="Todavía no hay aulas"
                        descripcion="Crea un curso con sus módulos y lecciones; al publicarlo y asignarlo se convierte en un aula."
                    />

                    <ListaAnimada
                        v-else
                        tag="div"
                        :cantidad="portada.cursos.length"
                        class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
                    >
                        <component
                            :is="puede.ver_cursos ? Link : 'div'"
                            v-for="curso in portada.cursos"
                            :key="curso.id"
                            :href="
                                puede.ver_cursos ? aula(curso.id) : undefined
                            "
                            class="flex flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
                            :class="{ 'tarjeta-interactiva': puede.ver_cursos }"
                        >
                            <PortadaCurso
                                :curso-id="curso.id"
                                :titulo="curso.titulo"
                            >
                                <Badge
                                    class="absolute top-3 right-3"
                                    :variant="
                                        curso.estado === 'publicado'
                                            ? 'default'
                                            : 'secondary'
                                    "
                                    >{{ curso.estado_etiqueta }}</Badge
                                >
                            </PortadaCurso>
                            <div class="flex flex-1 flex-col gap-3 p-4">
                                <div class="min-w-0">
                                    <h3
                                        class="line-clamp-2 font-semibold text-pretty"
                                    >
                                        {{ curso.titulo }}
                                    </h3>
                                    <p
                                        class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                                    >
                                        {{
                                            curso.descripcion ||
                                            `${curso.modulos} módulos`
                                        }}
                                    </p>
                                </div>
                                <div
                                    class="mt-auto flex items-center justify-between gap-3"
                                >
                                    <div
                                        class="flex flex-col gap-0.5 text-xs text-muted-foreground"
                                    >
                                        <span class="flex items-center gap-1">
                                            <Users class="size-3.5" />
                                            {{ curso.inscritos }} inscritos
                                        </span>
                                        <span
                                            v-if="curso.genera_constancia"
                                            class="flex items-center gap-1 text-bronce"
                                        >
                                            <Award class="size-3.5" />
                                            Con constancia
                                        </span>
                                    </div>
                                    <AnilloProgreso
                                        class="text-esmeralda"
                                        :valor="
                                            porcentaje(
                                                curso.completaron,
                                                curso.inscritos,
                                            )
                                        "
                                        :tamano="48"
                                        :grosor="5"
                                    />
                                </div>
                            </div>
                        </component>
                    </ListaAnimada>
                </section>

                <section
                    v-if="portada.proximas_sesiones.length > 0"
                    class="flex flex-col gap-3"
                >
                    <h2 class="text-lg font-semibold">Próximas sesiones</h2>
                    <ul class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <li
                            v-for="sesion in portada.proximas_sesiones"
                            :key="sesion.id"
                            class="flex items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                        >
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-info-soft text-primary"
                            >
                                <CalendarClock class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <p class="font-medium">{{ sesion.titulo }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        formatearFechaHora(sesion.fecha_inicio)
                                    }}
                                    · {{ sesion.duracion_minutos }} min
                                </p>
                                <p
                                    v-if="sesion.curso"
                                    class="truncate text-xs text-muted-foreground"
                                >
                                    {{ sesion.curso }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </section>
            </template>
        </Deferred>
    </div>
</template>
