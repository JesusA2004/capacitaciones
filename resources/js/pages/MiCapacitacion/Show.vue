<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ArrowRight,
    Award,
    CheckCircle2,
    ChevronDown,
    ClipboardCheck,
    Clock,
    ExternalLink,
    FileText,
    HelpCircle,
    Link2,
    Lock,
    PlayCircle,
    ShieldCheck,
    Video,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref } from 'vue';
import AnilloProgreso from '@/components/Capacitacion/AnilloProgreso.vue';
import PortadaCurso from '@/components/Capacitacion/PortadaCurso.vue';
import ReproductorVideo from '@/components/MiCapacitacion/ReproductorVideo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { useAlertas } from '@/composables/useAlertas';
import { duracionLegible } from '@/lib/capacitacion';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index } from '@/routes/mi-capacitacion';
import { descargar as descargarConstancia } from '@/routes/mi-capacitacion/constancias';
import { completar } from '@/routes/mi-capacitacion/lecciones';
import { show as showActividad } from '@/routes/mi-capacitacion/lecciones/actividad';
import { show as showCuestionario } from '@/routes/mi-capacitacion/lecciones/cuestionario';
import { show as showSesion } from '@/routes/mi-capacitacion/lecciones/sesion';
import type {
    CertificadoItem,
    CursoItem,
    EstadoLeccion,
    InscripcionResumen,
    LeccionItem,
} from '@/types';

const props = defineProps<{
    curso: CursoItem;
    inscripcion: InscripcionResumen;
    estadoLecciones: Record<number, EstadoLeccion>;
    certificado: CertificadoItem | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mi capacitación', href: index.url() },
            { title: 'Curso', href: '#' },
        ],
    },
});

const { mostrarAdvertencia, mostrarExito } = useAlertas();

const TIPOS: Record<string, { icono: Component; clase: string }> = {
    video: { icono: PlayCircle, clase: 'bg-info-soft text-primary' },
    documento: { icono: FileText, clase: 'bg-crema text-bronce' },
    guia: { icono: FileText, clase: 'bg-crema text-bronce' },
    texto: { icono: FileText, clase: 'bg-crema text-bronce' },
    enlace: { icono: Link2, clase: 'bg-salvia text-primary' },
    cuestionario: { icono: HelpCircle, clase: 'bg-warning-soft text-bronce' },
    actividad: { icono: ClipboardCheck, clase: 'bg-menta text-esmeralda' },
    sesion_en_vivo: { icono: Video, clase: 'bg-oro/20 text-bronce' },
    confirmacion: { icono: ShieldCheck, clase: 'bg-success-soft text-primary' },
};

/** Tipos que se resuelven en su propia pantalla (no se marcan a mano). */
const CON_PANTALLA = ['cuestionario', 'actividad', 'sesion_en_vivo'];

const modulos = computed(() => props.curso.modulos ?? []);
const lecciones = computed(() => modulos.value.flatMap((m) => m.lecciones));
const completadas = computed(
    () =>
        lecciones.value.filter((l) => props.estadoLecciones[l.id]?.completada)
            .length,
);
const progreso = computed(() =>
    props.inscripcion.estado === 'completada'
        ? 100
        : lecciones.value.length === 0
          ? 0
          : Math.round((100 * completadas.value) / lecciones.value.length),
);

/** Primera lección desbloqueada sin completar: el «Continuar». */
const siguiente = computed(() =>
    lecciones.value.find((l) => {
        const estado = props.estadoLecciones[l.id];

        return estado && !estado.completada && !estado.bloqueada;
    }),
);

const leccionAbiertaId = ref<number | null>(siguiente.value?.id ?? null);

function completadasDe(leccionesModulo: LeccionItem[]): number {
    return leccionesModulo.filter(
        (l) => props.estadoLecciones[l.id]?.completada,
    ).length;
}

function rutaPropia(leccion: LeccionItem): string | null {
    switch (leccion.tipo) {
        case 'cuestionario':
            return showCuestionario.url(leccion.id);
        case 'actividad':
            return showActividad.url(leccion.id);
        case 'sesion_en_vivo':
            return showSesion.url(leccion.id);
        default:
            return null;
    }
}

function textoRuta(tipo: string): string {
    return tipo === 'cuestionario'
        ? 'Resolver cuestionario'
        : tipo === 'actividad'
          ? 'Ver actividad y entregar'
          : 'Ver sesión en vivo';
}

function alternarLeccion(leccion: LeccionItem) {
    const estado = props.estadoLecciones[leccion.id];

    if (estado?.bloqueada) {
        mostrarAdvertencia(
            estado.motivo_bloqueo ?? 'Esta lección está bloqueada.',
        );

        return;
    }

    leccionAbiertaId.value =
        leccionAbiertaId.value === leccion.id ? null : leccion.id;
}

function continuar() {
    if (!siguiente.value) {
        return;
    }

    const ruta = rutaPropia(siguiente.value);

    if (ruta) {
        router.visit(ruta);

        return;
    }

    leccionAbiertaId.value = siguiente.value.id;
    document
        .getElementById(`leccion-${siguiente.value.id}`)
        ?.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function completarLeccion(leccionId: number) {
    router.post(
        completar.url(leccionId),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Lección completada correctamente.'),
        },
    );
}

function alCompletarVideo() {
    router.reload({ only: ['estadoLecciones', 'inscripcion', 'certificado'] });
}
</script>

<template>
    <Head :title="curso.titulo" />

    <div class="pagina-ancha flex flex-col gap-6">
        <section
            class="entrada-suave overflow-hidden rounded-3xl border border-border/60 bg-card shadow-sm"
        >
            <PortadaCurso
                :curso-id="curso.id"
                :titulo="curso.titulo"
                alto="h-36 sm:h-44"
            >
                <div
                    class="absolute inset-x-0 bottom-0 flex items-end justify-between gap-4 p-5 sm:p-6"
                >
                    <div class="min-w-0">
                        <Badge
                            variant="secondary"
                            class="mb-2 shadow-sm"
                            :class="
                                inscripcion.estado === 'completada'
                                    ? 'bg-success-soft text-primary'
                                    : 'bg-card/80'
                            "
                        >
                            {{
                                inscripcion.estado === 'completada'
                                    ? 'Completado'
                                    : inscripcion.estado === 'en_progreso'
                                      ? 'En progreso'
                                      : 'Por empezar'
                            }}
                        </Badge>
                        <h1
                            class="text-2xl font-bold text-balance text-foreground sm:text-3xl"
                        >
                            {{ curso.titulo }}
                        </h1>
                    </div>
                    <span
                        class="hidden shrink-0 rounded-full bg-card/80 p-1.5 shadow-sm sm:block"
                    >
                        <AnilloProgreso
                            class="text-esmeralda"
                            :valor="progreso"
                            :tamano="64"
                            :grosor="6"
                        />
                    </span>
                </div>
            </PortadaCurso>

            <div
                class="flex flex-col gap-4 border-t border-border/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between sm:px-6"
            >
                <div class="min-w-0 space-y-1">
                    <p
                        v-if="curso.objetivo || curso.descripcion"
                        class="text-sm text-pretty text-muted-foreground"
                    >
                        {{ curso.objetivo || curso.descripcion }}
                    </p>
                    <p
                        class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground"
                    >
                        <span class="tabular-nums"
                            >{{ completadas }} de
                            {{ lecciones.length }} lecciones</span
                        >
                        <span
                            v-if="curso.duracion_estimada_minutos"
                            class="inline-flex items-center gap-1"
                            ><Clock class="size-3.5" />
                            {{
                                duracionLegible(curso.duracion_estimada_minutos)
                            }}</span
                        >
                        <span
                            v-if="curso.genera_constancia"
                            class="inline-flex items-center gap-1 text-bronce"
                            ><Award class="size-3.5" /> Otorga constancia</span
                        >
                    </p>
                </div>
                <div class="flex shrink-0 flex-wrap gap-2">
                    <Button variant="outline" as-child>
                        <Link :href="index()">
                            <ArrowLeft class="size-4" /> Mis cursos
                        </Link>
                    </Button>
                    <Button v-if="certificado" variant="secondary" as-child>
                        <a :href="descargarConstancia.url(certificado.id)">
                            <Award class="size-4" />
                            Descargar constancia
                        </a>
                    </Button>
                    <Button v-if="siguiente" @click="continuar">
                        {{ completadas === 0 ? 'Empezar' : 'Continuar' }}
                        <ArrowRight class="size-4" />
                    </Button>
                </div>
            </div>
        </section>

        <div
            v-if="inscripcion.estado === 'completada'"
            class="flex entrada-suave items-center gap-3 rounded-2xl border border-border/60 bg-success-soft/70 px-4 py-3 text-sm"
        >
            <CheckCircle2 class="size-5 shrink-0 text-primary" />
            <p>
                ¡Terminaste este curso{{
                    inscripcion.completado_en
                        ? ` el ${formatearFecha(inscripcion.completado_en)}`
                        : ''
                }}!
                <template v-if="certificado">
                    Tu constancia tiene el folio
                    <strong class="font-mono">{{ certificado.folio }}</strong
                    >.
                </template>
            </p>
        </div>

        <div class="grid grid-cols-1 items-start gap-4 2xl:grid-cols-2">
            <section
                v-for="(modulo, m) in modulos"
                :key="modulo.id"
                class="overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            >
                <header
                    class="flex items-center gap-3 border-b border-border/60 bg-crema/40 px-4 py-3"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-card text-sm font-bold text-bronce tabular-nums shadow-sm ring-1 ring-border/60"
                        >{{ m + 1 }}</span
                    >
                    <div class="min-w-0 flex-1">
                        <h2 class="truncate font-semibold">
                            {{ modulo.titulo }}
                        </h2>
                        <p
                            v-if="modulo.descripcion"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ modulo.descripcion }}
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full bg-card px-2.5 py-0.5 text-xs font-medium text-muted-foreground tabular-nums"
                        >{{ completadasDe(modulo.lecciones) }}/{{
                            modulo.lecciones.length
                        }}</span
                    >
                </header>

                <ol class="flex flex-col divide-y divide-border/60">
                    <li
                        v-for="leccion in modulo.lecciones"
                        :id="`leccion-${leccion.id}`"
                        :key="leccion.id"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left transicion-ui hover:bg-crema/30"
                            :class="{
                                'opacity-60':
                                    estadoLecciones[leccion.id]?.bloqueada,
                                'bg-salvia/30':
                                    siguiente?.id === leccion.id &&
                                    leccionAbiertaId !== leccion.id,
                            }"
                            :aria-expanded="leccionAbiertaId === leccion.id"
                            @click="alternarLeccion(leccion)"
                        >
                            <span
                                class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                                :class="
                                    estadoLecciones[leccion.id]?.completada
                                        ? 'bg-success-soft text-primary'
                                        : (TIPOS[leccion.tipo]?.clase ??
                                          'bg-muted text-muted-foreground')
                                "
                            >
                                <CheckCircle2
                                    v-if="
                                        estadoLecciones[leccion.id]?.completada
                                    "
                                    class="size-5"
                                />
                                <Lock
                                    v-else-if="
                                        estadoLecciones[leccion.id]?.bloqueada
                                    "
                                    class="size-4"
                                />
                                <component
                                    :is="TIPOS[leccion.tipo]?.icono ?? FileText"
                                    v-else
                                    class="size-4"
                                />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block truncate text-sm font-medium"
                                >
                                    {{ leccion.titulo }}
                                </span>
                                <span
                                    class="flex flex-wrap gap-x-2 text-xs text-muted-foreground"
                                >
                                    <span
                                        v-if="siguiente?.id === leccion.id"
                                        class="font-medium text-primary"
                                        >Siguiente</span
                                    >
                                    <span v-if="!leccion.obligatoria"
                                        >Opcional</span
                                    >
                                    <span
                                        v-if="leccion.duracion_estimada_minutos"
                                        >{{
                                            leccion.duracion_estimada_minutos
                                        }}
                                        min</span
                                    >
                                </span>
                            </span>
                            <ChevronDown
                                class="size-4 shrink-0 text-muted-foreground transition-transform duration-200"
                                :class="{
                                    'rotate-180':
                                        leccionAbiertaId === leccion.id,
                                }"
                            />
                        </button>

                        <CambioSuave>
                            <div
                                v-if="leccionAbiertaId === leccion.id"
                                :key="`abierta-${leccion.id}`"
                                class="flex flex-col gap-3 border-t border-border/60 bg-crema/20 px-4 py-4"
                            >
                                <ReproductorVideo
                                    v-if="
                                        leccion.tipo === 'video' &&
                                        leccion.recurso_multimedia_id
                                    "
                                    :key="leccion.id"
                                    :leccion-id="leccion.id"
                                    @completada="alCompletarVideo"
                                />

                                <p
                                    v-if="leccion.contenido"
                                    class="max-w-prose text-sm whitespace-pre-line"
                                >
                                    {{ leccion.contenido }}
                                </p>

                                <div class="flex flex-wrap items-center gap-2">
                                    <Button
                                        v-if="rutaPropia(leccion)"
                                        size="sm"
                                        as-child
                                    >
                                        <Link :href="rutaPropia(leccion) ?? ''">
                                            {{ textoRuta(leccion.tipo) }}
                                            <ArrowRight class="size-4" />
                                        </Link>
                                    </Button>
                                    <Button
                                        v-if="leccion.url"
                                        size="sm"
                                        variant="outline"
                                        as-child
                                    >
                                        <a
                                            :href="leccion.url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            <ExternalLink class="size-4" />
                                            Abrir enlace
                                        </a>
                                    </Button>
                                    <Button
                                        v-if="
                                            leccion.tipo !== 'video' &&
                                            !CON_PANTALLA.includes(
                                                leccion.tipo,
                                            ) &&
                                            !estadoLecciones[leccion.id]
                                                ?.completada
                                        "
                                        size="sm"
                                        @click="completarLeccion(leccion.id)"
                                    >
                                        <CheckCircle2 class="size-4" />
                                        Marcar como completada
                                    </Button>
                                    <span
                                        v-else-if="
                                            estadoLecciones[leccion.id]
                                                ?.completada
                                        "
                                        class="inline-flex items-center gap-1.5 text-sm text-primary"
                                    >
                                        <CheckCircle2 class="size-4" />
                                        Ya completaste esta lección.
                                    </span>
                                </div>
                            </div>
                        </CambioSuave>
                    </li>
                </ol>
            </section>
        </div>
    </div>
</template>
