<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    Award,
    CalendarClock,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    HelpCircle,
    Megaphone,
    Pencil,
    Send,
    Users,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AnilloProgreso from '@/components/Capacitacion/AnilloProgreso.vue';
import EncabezadoCurso from '@/components/Capacitacion/EncabezadoCurso.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Input } from '@/components/ui/input';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import { Progress } from '@/components/ui/progress';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { create as nuevaAsignacion } from '@/routes/asignaciones';
import { index as indexCalificacionesActividades } from '@/routes/calificaciones/actividades';
import { index as indexCalificacionesCuestionarios } from '@/routes/calificaciones/cuestionarios';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { edit, index as indexCursos } from '@/routes/cursos';
import type { AulaCurso, EstadoCeldaAula } from '@/types';

const props = defineProps<{
    aula: AulaCurso;
    puede: { editar: boolean; asignar: boolean; calificar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Cursos', href: indexCursos() },
            { title: 'Aula', href: '#' },
        ],
    },
});

type Pestana = 'tablon' | 'trabajo' | 'calificaciones' | 'participantes';

const PESTANAS: { clave: Pestana; texto: string; icono: Component }[] = [
    { clave: 'tablon', texto: 'Tablón', icono: Megaphone },
    { clave: 'trabajo', texto: 'Trabajo asignado', icono: ClipboardCheck },
    { clave: 'calificaciones', texto: 'Calificaciones', icono: CheckCircle2 },
    { clave: 'participantes', texto: 'Participantes', icono: Users },
];

const pestana = ref<Pestana>('tablon');
const busqueda = ref('');

const participantesFiltrados = computed(() => {
    const texto = busqueda.value.trim().toLowerCase();

    return texto === ''
        ? props.aula.participantes
        : props.aula.participantes.filter((p) =>
              `${p.nombre} ${p.correo ?? ''}`.toLowerCase().includes(texto),
          );
});

const ICONO_TIPO: Record<string, Component> = {
    cuestionario: HelpCircle,
    actividad: FileText,
    sesion_en_vivo: Video,
};

const ICONO_TABLON: Record<string, Component> = {
    sesion: CalendarClock,
    entrega: FileText,
    cuestionario: HelpCircle,
    constancia: Award,
};

const CELDA: Record<EstadoCeldaAula, { texto: string; clase: string }> = {
    aprobado: { texto: 'Aprobado', clase: 'bg-success-soft text-primary' },
    reprobado: {
        texto: 'No aprobado',
        clase: 'bg-danger-soft text-destructive',
    },
    por_calificar: {
        texto: 'Por calificar',
        clase: 'bg-warning-soft text-bronce',
    },
    completado: { texto: 'Completado', clase: 'bg-menta text-esmeralda' },
    sin_entregar: {
        texto: 'Sin entregar',
        clase: 'bg-muted text-muted-foreground',
    },
};
</script>

<template>
    <Head :title="`Aula · ${aula.curso.titulo}`" />

    <div class="pagina-ancha flex flex-col gap-6">
        <EncabezadoCurso
            :curso="{
                ...aula.curso,
                descripcion: aula.curso.descripcion || aula.curso.objetivo,
            }"
            actual="aula"
        >
            <Button
                v-if="puede.editar"
                variant="secondary"
                class="bg-card/80"
                as-child
            >
                <Link :href="edit(aula.curso.id)">
                    <Pencil class="size-4" />
                    Editar contenido
                </Link>
            </Button>
            <Button v-if="puede.asignar" as-child>
                <Link
                    :href="nuevaAsignacion({ query: { curso: aula.curso.id } })"
                >
                    <Send class="size-4" />
                    Asignar
                </Link>
            </Button>
        </EncabezadoCurso>

        <div class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-5">
            <MetricCard
                etiqueta="Participantes"
                :valor="aula.resumen.participantes"
                :icono="Users"
                color-clase="bg-salvia text-primary"
            />
            <MetricCard
                etiqueta="Terminaron"
                :valor="aula.resumen.completaron"
                :icono="CheckCircle2"
                color-clase="bg-success-soft text-primary"
            />
            <MetricCard
                etiqueta="Avance promedio"
                :valor="`${aula.resumen.progreso_promedio}%`"
                :icono="ClipboardCheck"
                color-clase="bg-menta text-esmeralda"
            />
            <MetricCard
                etiqueta="Por calificar"
                :valor="aula.resumen.por_calificar"
                :icono="HelpCircle"
                color-clase="bg-warning-soft text-bronce"
            />
            <MetricCard
                etiqueta="Constancias"
                :valor="aula.resumen.constancias"
                :icono="Award"
                color-clase="bg-oro/20 text-bronce"
            />
        </div>

        <nav
            class="flex gap-1 overflow-x-auto rounded-2xl border border-border/60 bg-crema/50 p-1"
            aria-label="Secciones del aula"
        >
            <button
                v-for="p in PESTANAS"
                :key="p.clave"
                type="button"
                class="flex shrink-0 presionable items-center gap-2 rounded-xl px-4 py-2 text-sm font-medium transicion-ui"
                :class="
                    pestana === p.clave
                        ? 'bg-card text-primary shadow-sm'
                        : 'text-muted-foreground hover:bg-card/60 hover:text-foreground'
                "
                :aria-current="pestana === p.clave ? 'page' : undefined"
                @click="pestana = p.clave"
            >
                <component :is="p.icono" class="size-4" />
                {{ p.texto }}
            </button>
        </nav>

        <CambioSuave>
            <!-- Tablón: actividad del aula (derivada de lo que ya existe). -->
            <section v-if="pestana === 'tablon'" key="tablon">
                <EmptyState
                    v-if="aula.tablon.length === 0"
                    :icono="Megaphone"
                    titulo="El tablón está tranquilo"
                    descripcion="Aquí aparecen las próximas sesiones, las entregas, los cuestionarios enviados y las constancias emitidas."
                />
                <ListaAnimada
                    v-else
                    tag="ol"
                    :cantidad="aula.tablon.length"
                    class="grid gap-3 xl:grid-cols-2"
                >
                    <li
                        v-for="(evento, i) in aula.tablon"
                        :key="`${evento.tipo}-${evento.fecha}-${i}`"
                        class="flex items-start gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl"
                            :class="
                                evento.tipo === 'constancia'
                                    ? 'bg-oro/20 text-bronce'
                                    : evento.tipo === 'sesion'
                                      ? 'bg-info-soft text-primary'
                                      : 'bg-salvia text-primary'
                            "
                        >
                            <component
                                :is="ICONO_TABLON[evento.tipo]"
                                class="size-5"
                            />
                        </span>
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-pretty">
                                {{ evento.titulo }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ evento.detalle }} ·
                                {{ formatearFechaHora(evento.fecha) }}
                            </p>
                        </div>
                    </li>
                </ListaAnimada>
            </section>

            <!-- Trabajo asignado: lecciones calificables. -->
            <section
                v-else-if="pestana === 'trabajo'"
                key="trabajo"
                class="flex flex-col gap-3"
            >
                <div
                    v-if="puede.calificar && aula.resumen.por_calificar > 0"
                    class="flex flex-wrap gap-2"
                >
                    <Button variant="secondary" size="sm" as-child>
                        <Link
                            :href="
                                indexCalificacionesCuestionarios({
                                    query: { curso: aula.curso.id },
                                })
                            "
                            >Calificar cuestionarios</Link
                        >
                    </Button>
                    <Button variant="secondary" size="sm" as-child>
                        <Link
                            :href="
                                indexCalificacionesActividades({
                                    query: { curso: aula.curso.id },
                                })
                            "
                            >Calificar actividades</Link
                        >
                    </Button>
                </div>
                <EmptyState
                    v-if="aula.trabajo.length === 0"
                    :icono="ClipboardCheck"
                    titulo="Sin trabajo asignado"
                    descripcion="Agrega lecciones de cuestionario, actividad o sesión en vivo desde el constructor del curso."
                />
                <ul v-else class="grid gap-3 md:grid-cols-2 2xl:grid-cols-3">
                    <li
                        v-for="trabajo in aula.trabajo"
                        :key="trabajo.leccion_id"
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <div class="flex items-start gap-3">
                            <span
                                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-menta text-esmeralda"
                            >
                                <component
                                    :is="ICONO_TIPO[trabajo.tipo] ?? FileText"
                                    class="size-5"
                                />
                            </span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium">{{ trabajo.titulo }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ trabajo.tipo_etiqueta }}
                                    <template v-if="trabajo.fecha">
                                        · {{ formatearFecha(trabajo.fecha) }}
                                    </template>
                                    <template v-if="!trabajo.obligatoria">
                                        · opcional</template
                                    >
                                </p>
                            </div>
                            <Badge
                                v-if="trabajo.por_calificar > 0"
                                class="bg-warning-soft text-bronce"
                                variant="secondary"
                                >{{ trabajo.por_calificar }} por
                                calificar</Badge
                            >
                        </div>
                        <div class="flex items-center gap-3">
                            <Progress
                                :model-value="
                                    trabajo.participantes === 0
                                        ? 0
                                        : (100 * trabajo.completaron) /
                                          trabajo.participantes
                                "
                                class="h-2 flex-1"
                            />
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                                >{{ trabajo.completaron }}/{{
                                    trabajo.participantes
                                }}</span
                            >
                        </div>
                    </li>
                </ul>
            </section>

            <!-- Calificaciones: participante × trabajo calificable. -->
            <section
                v-else-if="pestana === 'calificaciones'"
                key="calificaciones"
            >
                <EmptyState
                    v-if="
                        aula.columnas.length === 0 ||
                        aula.calificaciones.length === 0
                    "
                    :icono="CheckCircle2"
                    titulo="Aún no hay calificaciones"
                    descripcion="La tabla se llena cuando el aula tiene participantes y trabajo calificable."
                />
                <div
                    v-else
                    class="overflow-x-auto rounded-2xl border border-border/60 bg-card shadow-sm"
                >
                    <table class="w-full text-sm">
                        <thead class="bg-crema/60 text-left">
                            <tr>
                                <th
                                    class="sticky left-0 bg-crema px-4 py-3 font-semibold"
                                >
                                    Participante
                                </th>
                                <th
                                    v-for="columna in aula.columnas"
                                    :key="columna.leccion_id"
                                    class="min-w-36 px-3 py-3 font-medium"
                                >
                                    {{ columna.titulo }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="fila in aula.calificaciones"
                                :key="fila.user_id"
                                class="border-t border-border/60"
                            >
                                <td
                                    class="sticky left-0 bg-card px-4 py-2 font-medium"
                                >
                                    {{ fila.nombre }}
                                </td>
                                <td
                                    v-for="columna in aula.columnas"
                                    :key="columna.leccion_id"
                                    class="px-3 py-2"
                                >
                                    <span
                                        v-if="fila.celdas[columna.leccion_id]"
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                                        :class="
                                            CELDA[
                                                fila.celdas[columna.leccion_id]
                                                    .estado
                                            ].clase
                                        "
                                    >
                                        {{
                                            fila.celdas[columna.leccion_id]
                                                .valor ??
                                            CELDA[
                                                fila.celdas[columna.leccion_id]
                                                    .estado
                                            ].texto
                                        }}
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Participantes. -->
            <section v-else key="participantes" class="flex flex-col gap-3">
                <Input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar participante…"
                    class="max-w-sm"
                />
                <EmptyState
                    v-if="participantesFiltrados.length === 0"
                    :icono="Users"
                    titulo="Sin participantes"
                    :descripcion="
                        aula.participantes.length === 0
                            ? 'Asigna este curso para que la gente se inscriba en el aula.'
                            : 'Nadie coincide con la búsqueda.'
                    "
                />
                <ListaAnimada
                    v-else
                    tag="ul"
                    :cantidad="participantesFiltrados.length"
                    class="grid gap-3 md:grid-cols-2 xl:grid-cols-3"
                >
                    <li
                        v-for="persona in participantesFiltrados"
                        :key="persona.user_id"
                        class="flex items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <AnilloProgreso
                            class="text-esmeralda"
                            :valor="persona.progreso"
                            :tamano="48"
                            :grosor="5"
                        />
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">
                                {{ persona.nombre }}
                            </p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ persona.estado_etiqueta }}
                                <template v-if="persona.completado_en">
                                    ·
                                    {{ formatearFecha(persona.completado_en) }}
                                </template>
                            </p>
                        </div>
                        <Badge
                            v-if="persona.folio"
                            variant="secondary"
                            class="bg-oro/20 text-bronce"
                            :title="`Folio ${persona.folio}`"
                        >
                            <Award class="size-3.5" />
                            Constancia
                        </Badge>
                    </li>
                </ListaAnimada>
            </section>
        </CambioSuave>
    </div>
</template>
