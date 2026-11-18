<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import {
    Award,
    BookOpenCheck,
    CalendarClock,
    CheckCircle2,
    Clock,
    Download,
    FileText,
    GraduationCap,
    HelpCircle,
    ListTodo,
    PlayCircle,
    ShieldCheck,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed } from 'vue';
import AnilloProgreso from '@/components/Capacitacion/AnilloProgreso.vue';
import InsigniaNivel from '@/components/Capacitacion/InsigniaNivel.vue';
import PortadaCurso from '@/components/Capacitacion/PortadaCurso.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { verificar as verificarConstancia } from '@/routes/constancias';
import {
    certificados as rutaCertificados,
    index,
    pendientes as rutaPendientes,
    show,
} from '@/routes/mi-capacitacion';
import { descargar as descargarConstancia } from '@/routes/mi-capacitacion/constancias';
import type { PanelMiCapacitacion } from '@/types';

type Seccion = 'aulas' | 'pendientes' | 'certificados';

const props = defineProps<{
    seccion: Seccion;
    panel?: PanelMiCapacitacion;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
        ],
    },
});

const SECCIONES: {
    clave: Seccion;
    texto: string;
    icono: Component;
    href: () => ReturnType<typeof index>;
}[] = [
    { clave: 'aulas', texto: 'Mis aulas', icono: GraduationCap, href: index },
    {
        clave: 'pendientes',
        texto: 'Pendientes',
        icono: ListTodo,
        href: rutaPendientes,
    },
    {
        clave: 'certificados',
        texto: 'Mis certificados',
        icono: Award,
        href: rutaCertificados,
    },
];

const ICONO_TIPO: Record<string, Component> = {
    video: PlayCircle,
    cuestionario: HelpCircle,
    actividad: FileText,
    sesion_en_vivo: Video,
};

const horas = computed(
    () =>
        Math.round(
            ((props.panel?.resumen.minutos_completados ?? 0) / 60) * 10,
        ) / 10,
);

function contadorSeccion(clave: Seccion): number | null {
    if (!props.panel) {
        return null;
    }

    if (clave === 'aulas') {
        return props.panel.aulas.length;
    }

    return clave === 'pendientes'
        ? props.panel.resumen.pendientes
        : props.panel.certificados.length;
}
</script>

<template>
    <Head title="Mi capacitación" />

    <div class="pagina-ancha flex flex-col gap-6">
        <section
            data-tour="mi-capacitacion-encabezado"
            class="relative entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-linear-to-br from-menta via-salvia to-crema p-6 shadow-sm sm:p-8"
        >
            <span
                class="pointer-events-none absolute -top-14 -right-12 size-52 rounded-full bg-card/30"
                aria-hidden="true"
            />
            <div
                class="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between"
            >
                <Heading
                    title="Mi capacitación"
                    description="Tus aulas, lo que te falta por hacer y tus constancias de la Certificación Mr. Lana."
                />

                <Deferred data="panel">
                    <template #fallback>
                        <Skeleton class="h-24 w-full rounded-2xl lg:w-96" />
                    </template>
                    <div
                        v-if="panel"
                        data-tour="mi-capacitacion-certificacion"
                        class="flex items-center gap-4 rounded-2xl bg-card/80 p-4 shadow-sm backdrop-blur lg:w-96"
                    >
                        <AnilloProgreso
                            class="text-oro"
                            :valor="panel.certificacion.progreso"
                            :tamano="64"
                            :grosor="7"
                        >
                            <Award class="size-5 text-bronce" />
                        </AnilloProgreso>
                        <div class="min-w-0">
                            <p
                                class="text-xs font-semibold tracking-wide text-bronce uppercase"
                            >
                                Certificación Mr. Lana
                            </p>
                            <InsigniaNivel
                                v-if="panel.certificacion.nivel"
                                class="mt-1"
                                :nivel="panel.certificacion.nivel.clave"
                                :etiqueta="panel.certificacion.nivel.etiqueta"
                            />
                            <p v-else class="text-sm font-medium">
                                Aún sin nivel
                            </p>
                            <p
                                v-if="panel.certificacion.siguiente"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                Te
                                {{
                                    panel.certificacion.siguiente.faltan === 1
                                        ? 'falta 1 constancia'
                                        : `faltan ${panel.certificacion.siguiente.faltan} constancias`
                                }}
                                para
                                {{ panel.certificacion.siguiente.etiqueta }}
                            </p>
                            <p
                                v-else
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                ¡Alcanzaste el nivel más alto!
                            </p>
                        </div>
                    </div>
                </Deferred>
            </div>
        </section>

        <nav
            class="flex gap-1 overflow-x-auto rounded-2xl border border-border/60 bg-crema/50 p-1"
            data-tour="mi-capacitacion-secciones"
            aria-label="Secciones de Mi capacitación"
        >
            <Link
                v-for="s in SECCIONES"
                :key="s.clave"
                :href="s.href()"
                preserve-scroll
                class="flex shrink-0 presionable items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transicion-ui"
                :class="
                    seccion === s.clave
                        ? 'bg-card text-primary shadow-sm'
                        : 'text-muted-foreground hover:bg-card/60 hover:text-foreground'
                "
                :aria-current="seccion === s.clave ? 'page' : undefined"
            >
                <component :is="s.icono" class="size-4" />
                {{ s.texto }}
                <span
                    v-if="contadorSeccion(s.clave)"
                    class="rounded-full bg-salvia px-2 text-xs text-primary tabular-nums"
                    >{{ contadorSeccion(s.clave) }}</span
                >
            </Link>
        </nav>

        <Deferred data="panel">
            <template #fallback>
                <div class="grid gap-4" aria-busy="true">
                    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                        <Skeleton
                            v-for="n in 4"
                            :key="n"
                            class="h-24 rounded-2xl"
                        />
                    </div>
                    <div
                        class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                    >
                        <Skeleton
                            v-for="n in 3"
                            :key="n"
                            class="h-60 rounded-2xl"
                        />
                    </div>
                </div>
            </template>

            <template v-if="panel">
                <div
                    data-tour="mi-capacitacion-resumen"
                    class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4"
                >
                    <MetricCard
                        etiqueta="En curso"
                        :valor="panel.resumen.en_curso"
                        :icono="BookOpenCheck"
                        color-clase="bg-salvia text-primary"
                    />
                    <MetricCard
                        etiqueta="Terminados"
                        :valor="panel.resumen.completadas"
                        :icono="CheckCircle2"
                        color-clase="bg-success-soft text-primary"
                    />
                    <MetricCard
                        etiqueta="Horas de capacitación"
                        :valor="horas"
                        :icono="Clock"
                        color-clase="bg-menta text-esmeralda"
                    />
                    <MetricCard
                        etiqueta="Constancias"
                        :valor="panel.resumen.constancias"
                        :icono="Award"
                        color-clase="bg-oro/20 text-bronce"
                    />
                </div>

                <CambioSuave>
                    <!-- Mis aulas -->
                    <section v-if="seccion === 'aulas'" key="aulas">
                        <EmptyState
                            v-if="panel.aulas.length === 0"
                            :icono="GraduationCap"
                            titulo="Sin aulas por ahora"
                            descripcion="Cuando RH o tu jefe te asignen un curso, aparecerá aquí como un aula con su avance."
                        />
                        <ListaAnimada
                            v-else
                            tag="div"
                            :cantidad="panel.aulas.length"
                            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                        >
                            <Link
                                v-for="aula in panel.aulas"
                                :key="aula.inscripcion_id"
                                :href="show(aula.curso.id)"
                                class="flex tarjeta-interactiva flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
                            >
                                <PortadaCurso
                                    :curso-id="aula.curso.id"
                                    :titulo="aula.curso.titulo"
                                >
                                    <Badge
                                        class="absolute top-3 right-3"
                                        :variant="
                                            aula.estado === 'completada'
                                                ? 'default'
                                                : 'secondary'
                                        "
                                        >{{ aula.estado_etiqueta }}</Badge
                                    >
                                </PortadaCurso>
                                <div class="flex flex-1 flex-col gap-3 p-4">
                                    <div class="min-w-0">
                                        <h3
                                            class="line-clamp-2 font-semibold text-pretty"
                                        >
                                            {{ aula.curso.titulo }}
                                        </h3>
                                        <p
                                            v-if="aula.curso.descripcion"
                                            class="mt-1 line-clamp-2 text-xs text-muted-foreground"
                                        >
                                            {{ aula.curso.descripcion }}
                                        </p>
                                    </div>
                                    <div class="mt-auto flex flex-col gap-2">
                                        <div
                                            class="flex items-center justify-between text-xs text-muted-foreground"
                                        >
                                            <span
                                                >{{
                                                    aula.lecciones_completadas
                                                }}
                                                de
                                                {{ aula.lecciones_total }}
                                                lecciones</span
                                            >
                                            <span
                                                class="font-semibold text-foreground tabular-nums"
                                                >{{ aula.progreso }}%</span
                                            >
                                        </div>
                                        <Progress
                                            :model-value="aula.progreso"
                                            class="h-2"
                                        />
                                        <p
                                            v-if="
                                                aula.fecha_limite &&
                                                aula.estado !== 'completada'
                                            "
                                            class="flex items-center gap-1 text-xs text-bronce"
                                        >
                                            <CalendarClock class="size-3.5" />
                                            Fecha límite:
                                            {{
                                                formatearFecha(
                                                    aula.fecha_limite,
                                                )
                                            }}
                                        </p>
                                        <p
                                            v-else-if="aula.certificado_id"
                                            class="flex items-center gap-1 text-xs text-bronce"
                                        >
                                            <Award class="size-3.5" />
                                            Constancia obtenida
                                        </p>
                                    </div>
                                </div>
                            </Link>
                        </ListaAnimada>
                    </section>

                    <!-- Pendientes -->
                    <section
                        v-else-if="seccion === 'pendientes'"
                        key="pendientes"
                    >
                        <EmptyState
                            v-if="panel.pendientes.length === 0"
                            :icono="CheckCircle2"
                            titulo="¡Estás al día!"
                            descripcion="No tienes lecciones pendientes en tus aulas."
                        />
                        <ListaAnimada
                            v-else
                            tag="ul"
                            :cantidad="panel.pendientes.length"
                            class="flex flex-col gap-3"
                        >
                            <li
                                v-for="pendiente in panel.pendientes"
                                :key="pendiente.leccion_id"
                            >
                                <Link
                                    :href="show(pendiente.curso_id)"
                                    class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                                >
                                    <span
                                        class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                                        :class="
                                            pendiente.estado === 'en_revision'
                                                ? 'bg-warning-soft text-bronce'
                                                : 'bg-salvia text-primary'
                                        "
                                    >
                                        <component
                                            :is="
                                                ICONO_TIPO[pendiente.tipo] ??
                                                BookOpenCheck
                                            "
                                            class="size-5"
                                        />
                                    </span>
                                    <div class="min-w-0 flex-1">
                                        <p class="font-medium">
                                            {{ pendiente.titulo }}
                                        </p>
                                        <p
                                            class="truncate text-xs text-muted-foreground"
                                        >
                                            {{ pendiente.curso }} ·
                                            {{ pendiente.tipo_etiqueta }}
                                            <template v-if="pendiente.fecha">
                                                ·
                                                {{
                                                    formatearFecha(
                                                        pendiente.fecha,
                                                    )
                                                }}
                                            </template>
                                        </p>
                                    </div>
                                    <Badge
                                        v-if="
                                            pendiente.estado === 'en_revision'
                                        "
                                        variant="secondary"
                                        class="bg-warning-soft text-bronce"
                                        >En revisión</Badge
                                    >
                                    <Badge
                                        v-else-if="!pendiente.obligatoria"
                                        variant="outline"
                                        >Opcional</Badge
                                    >
                                </Link>
                            </li>
                        </ListaAnimada>
                    </section>

                    <!-- Mis certificados -->
                    <section
                        v-else
                        key="certificados"
                        class="flex flex-col gap-4"
                    >
                        <div
                            class="flex flex-wrap items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                        >
                            <span class="text-sm font-medium">Niveles:</span>
                            <InsigniaNivel
                                v-for="nivel in panel.certificacion.niveles"
                                :key="nivel.clave"
                                :nivel="nivel.clave"
                                :etiqueta="`${nivel.etiqueta} · ${nivel.umbral}`"
                                :bloqueada="
                                    panel.certificacion.constancias <
                                    nivel.umbral
                                "
                                :title="nivel.descripcion"
                            />
                        </div>
                        <EmptyState
                            v-if="panel.certificados.length === 0"
                            :icono="Award"
                            titulo="Aún no tienes constancias"
                            descripcion="Termina un curso que otorgue constancia y aquí podrás descargarla."
                        />
                        <ListaAnimada
                            v-else
                            tag="ul"
                            :cantidad="panel.certificados.length"
                            class="grid gap-3 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4"
                        >
                            <li
                                v-for="certificado in panel.certificados"
                                :key="certificado.id"
                                class="flex flex-col gap-3 rounded-2xl border border-oro/30 bg-linear-to-br from-card to-crema/60 p-4 shadow-sm"
                            >
                                <div class="flex items-start gap-3">
                                    <span
                                        class="flex size-10 shrink-0 aparece-zoom items-center justify-center rounded-xl bg-oro/20 text-bronce"
                                    >
                                        <Award class="size-5" />
                                    </span>
                                    <div class="min-w-0">
                                        <p class="font-semibold text-pretty">
                                            {{ certificado.curso }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            Folio {{ certificado.folio }} ·
                                            {{
                                                formatearFecha(
                                                    certificado.emitido_en,
                                                )
                                            }}
                                        </p>
                                    </div>
                                </div>
                                <div class="mt-auto flex flex-wrap gap-2">
                                    <Button
                                        variant="secondary"
                                        size="sm"
                                        as-child
                                    >
                                        <a
                                            :href="
                                                descargarConstancia.url(
                                                    certificado.id,
                                                )
                                            "
                                        >
                                            <Download class="size-4" />
                                            Descargar
                                        </a>
                                    </Button>
                                    <Button variant="ghost" size="sm" as-child>
                                        <a
                                            :href="
                                                verificarConstancia.url(
                                                    certificado.folio,
                                                )
                                            "
                                            target="_blank"
                                            rel="noopener"
                                            title="Página pública para que cualquiera compruebe que tu constancia es auténtica"
                                        >
                                            <ShieldCheck class="size-4" />
                                            Verificación
                                        </a>
                                    </Button>
                                </div>
                            </li>
                        </ListaAnimada>
                    </section>
                </CambioSuave>
            </template>
        </Deferred>
    </div>
</template>
