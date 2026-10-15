<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    BookOpen,
    CheckCircle2,
    CirclePlay,
    Clock,
    Compass,
    GraduationCap,
    ListChecks,
    Route,
    Search,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import GuiaBuscador from '@/components/guia/GuiaBuscador.vue';
import GuiaFlujo from '@/components/guia/GuiaFlujo.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useGuia } from '@/composables/useGuia';
import { useNavegacion } from '@/composables/useNavegacion';
import { useTourGuiado } from '@/composables/useTourGuiado';
import {
    articulo,
    NOMBRE_ROL,
    PREGUNTAS_FRECUENTES,
    PROCESOS,
    preguntaVisible,
    procesoVisible,
    rol,
    ROLES_GUIA,
    rolVisible,
    veFlujoCompleto,
} from '@/lib/guia';
import { iconoGuia } from '@/lib/guia/iconos';
import type { RolGuiaId } from '@/lib/guia/tipos';
import { ARTICULOS_DE_MODULO, AYUDA_ESCRITA } from '@/lib/tours/ayudaEscrita';
import { recorridosDisponibles, tourDeRecorrido } from '@/lib/tours/recorridos';
import {
    modulosDisponibles,
    tourCompleto,
    tourDeModulo,
} from '@/lib/tours/registro';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});

defineProps<{
    video: {
        url: string;
        subtitulos: string | null;
        miniatura: string | null;
    } | null;
}>();

const { contextoTour, rolGuia, alcance } = useGuia();
const { tieneAmbosModos } = useNavegacion();
const { iniciar, haVisto } = useTourGuiado();

const buscador = ref<InstanceType<typeof GuiaBuscador> | null>(null);

/**
 * Solo lo que le toca a quien lee (rol + modo de navegación): un colaborador
 * nunca ve el flujo interno de reclutamiento, autorizaciones ni nómina RH.
 * super_admin ve la guía global.
 */
const procesos = computed(() =>
    PROCESOS.filter((p) => procesoVisible(p, alcance.value)),
);
const rolesVisibles = computed(() =>
    ROLES_GUIA.filter((r) => rolVisible(r.id, alcance.value)),
);
const flujoCompleto = computed(() => veFlujoCompleto(alcance.value));
const preguntas = computed(() =>
    PREGUNTAS_FRECUENTES.filter((p) => preguntaVisible(p, alcance.value)),
);

const SUBTITULO: Record<RolGuiaId, string> = {
    rh: 'Conoce el proceso completo desde la necesidad de personal hasta la vida laboral del colaborador.',
    gerente:
        'Lo que te toca con tu equipo y con los candidatos de tu sucursal: entrevistas, revisiones, visto bueno, actas, bajas y pendientes.',
    regional:
        'Lo que te toca en tu región: visto bueno de solicitudes, intervenciones que te piden y seguimiento de sucursales.',
    direccion_comercial:
        'Consulta de indicadores, vacantes y candidatos, e intervenciones de puestos superiores.',
    sistemas:
        'Cuentas, roles, accesos, la app, notificaciones y soporte técnico.',
    colaborador:
        'Todo lo que puedes hacer en Mi espacio: tus datos, documentos, inducción, solicitudes, avisos y celebraciones.',
};
const subtitulo = computed(() =>
    alcance.value.global ? SUBTITULO.rh : SUBTITULO[rolGuia.value],
);

const miRol = computed(() => rol(rolGuia.value));
const recorridos = computed(() => recorridosDisponibles(contextoTour.value));
const recorridoSistema = computed(() => tourCompleto(contextoTour.value));
const modulos = computed(() => modulosDisponibles(contextoTour.value));

function irAlRecorrido(): void {
    document
        .getElementById('recorrido')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function buscar(): void {
    document
        .getElementById('guia-inicio')
        ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    buscador.value?.enfocar();
}
</script>

<template>
    <Head title="Guía del sistema" />

    <div class="pagina-media flex flex-col gap-12">
        <!-- Encabezado -->
        <section
            id="guia-inicio"
            class="relative overflow-hidden rounded-[2rem] border border-[var(--mrl-borde)] bg-gradient-to-br from-crema via-[var(--mrl-superficie)] to-salvia/70 px-5 py-10 text-center sm:px-10"
        >
            <div class="mx-auto flex max-w-3xl flex-col items-center gap-5">
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-oro/40 bg-[var(--mrl-superficie)]/80 px-3 py-1 text-[11px] font-semibold tracking-[0.22em] text-bronce uppercase"
                >
                    <Compass class="size-3.5" /> MR. LANA PEOPLE
                </span>
                <h1
                    class="text-3xl font-semibold tracking-tight text-[var(--mrl-texto)] sm:text-4xl"
                >
                    Guía del sistema
                </h1>
                <p
                    class="max-w-2xl text-base text-pretty text-[var(--mrl-texto-suave)] sm:text-lg"
                >
                    {{ subtitulo }}
                </p>

                <div class="flex flex-wrap justify-center gap-2">
                    <Button
                        v-if="flujoCompleto"
                        size="lg"
                        class="gap-2"
                        @click="irAlRecorrido"
                    >
                        <Route class="size-4" /> Ver recorrido completo
                    </Button>
                    <Button
                        v-if="flujoCompleto"
                        size="lg"
                        variant="secondary"
                        class="gap-2"
                        as-child
                    >
                        <Link href="/ayuda/video">
                            <CirclePlay class="size-4" /> Ver tutorial en video
                        </Link>
                    </Button>
                    <Button
                        size="lg"
                        variant="outline"
                        class="gap-2 bg-[var(--mrl-superficie)]"
                        @click="buscar"
                    >
                        <Search class="size-4" /> Buscar en la guía
                    </Button>
                </div>

                <div class="mt-2 w-full max-w-2xl">
                    <GuiaBuscador ref="buscador" grande />
                </div>

                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    Estás viendo la guía como
                    <Link
                        :href="`/ayuda/rol/${rolGuia}`"
                        class="font-semibold text-[var(--mrl-petroleo)] underline-offset-2 hover:underline"
                    >
                        {{ miRol?.nombre ?? NOMBRE_ROL[rolGuia] }}
                    </Link>
                    <template v-if="tieneAmbosModos">
                        · cambia entre «Mi espacio» y «Operación RH» en el menú
                        para ver la otra guía
                    </template>
                </p>
            </div>
        </section>

        <!-- Flujo general: solo RH (y la ayuda global de super_admin) -->
        <section
            v-if="flujoCompleto"
            id="recorrido"
            class="scroll-mt-24 space-y-5"
        >
            <header class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p
                        class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                    >
                        El recorrido completo
                    </p>
                    <h2 class="text-2xl font-semibold tracking-tight">
                        De una vacante a un colaborador activo
                    </h2>
                    <p class="text-sm text-[var(--mrl-texto-suave)]">
                        Toca cualquier paso para ver quién lo hace, dónde y qué
                        pasa después.
                    </p>
                </div>
                <Button
                    variant="outline"
                    class="gap-2"
                    @click="iniciar(recorridoSistema)"
                >
                    <Compass class="size-4" /> Recorrido guiado en pantalla
                    <span class="text-xs text-[var(--mrl-texto-suave)]">
                        ({{ recorridoSistema.pasos.length }} pasos)
                    </span>
                </Button>
            </header>
            <GuiaFlujo />
        </section>

        <!-- Guía por proceso -->
        <section class="space-y-5">
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Guía por proceso
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    {{
                        alcance.roles.length === 1 &&
                        alcance.roles[0] === 'colaborador'
                            ? 'Lo que puedes hacer en PEOPLE'
                            : 'Elige un proceso'
                    }}
                </h2>
            </header>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <Link
                    v-for="p in procesos"
                    :key="p.id"
                    :href="`/ayuda/proceso/${p.id}`"
                    class="group flex tarjeta-interactiva flex-col gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
                >
                    <div class="flex items-start gap-4">
                        <span
                            class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-crema to-salvia text-[var(--mrl-petroleo)]"
                        >
                            <component
                                :is="iconoGuia(p.icono)"
                                class="size-6"
                            />
                        </span>
                        <div class="min-w-0">
                            <p
                                class="text-[11px] font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                            >
                                Proceso {{ p.numero }}
                            </p>
                            <h3 class="text-lg font-semibold">
                                {{ p.titulo }}
                            </h3>
                        </div>
                    </div>
                    <p
                        class="text-sm text-pretty text-[var(--mrl-texto-suave)]"
                    >
                        {{ p.resumen }}
                    </p>
                    <div class="mt-auto flex flex-wrap items-center gap-1.5">
                        <Badge
                            v-for="r in p.roles"
                            :key="r"
                            variant="secondary"
                            class="bg-salvia/70 text-[11px] text-[var(--mrl-texto)]"
                        >
                            {{ NOMBRE_ROL[r] }}
                        </Badge>
                    </div>
                    <div
                        class="flex items-center justify-between border-t border-[var(--mrl-borde)] pt-3 text-xs"
                    >
                        <span
                            class="flex items-center gap-1.5 text-[var(--mrl-texto-suave)]"
                        >
                            <Clock class="size-3.5" /> {{ p.tiempo }}
                        </span>
                        <span
                            class="flex items-center gap-1 font-semibold text-[var(--mrl-petroleo)] transition-all group-hover:gap-2"
                        >
                            Ver guía <ArrowRight class="size-3.5" />
                        </span>
                    </div>
                </Link>
            </div>
        </section>

        <!-- Por rol -->
        <section class="space-y-5">
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    ¿Qué hago según mi rol?
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    {{
                        rolesVisibles.length > 1
                            ? 'Lo que le toca a cada quien'
                            : 'Lo que te toca'
                    }}
                </h2>
            </header>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <Link
                    v-for="r in rolesVisibles"
                    :key="r.id"
                    :href="`/ayuda/rol/${r.id}`"
                    :class="[
                        'flex tarjeta-interactiva items-start gap-3 rounded-2xl border p-4',
                        r.id === rolGuia
                            ? 'border-oro/60 bg-crema/60'
                            : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]',
                    ]"
                >
                    <span
                        class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-salvia text-[var(--mrl-petroleo)]"
                    >
                        <component :is="iconoGuia(r.icono)" class="size-5" />
                    </span>
                    <span class="min-w-0">
                        <span class="flex items-center gap-2 font-semibold">
                            {{ r.nombre }}
                            <Badge
                                v-if="r.id === rolGuia"
                                class="bg-oro text-[10px] text-white"
                                >Tu rol</Badge
                            >
                        </span>
                        <span
                            class="line-clamp-2 text-sm text-[var(--mrl-texto-suave)]"
                            >{{ r.resumen }}</span
                        >
                        <span
                            class="mt-1 flex items-center gap-1 text-xs text-[var(--mrl-texto-suave)]"
                        >
                            <ListChecks class="size-3.5" />
                            {{ r.checklist.length }} tareas
                        </span>
                    </span>
                </Link>
            </div>
        </section>

        <!-- Recorridos guiados -->
        <section class="space-y-5">
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Recorridos guiados
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Aprende haciendo, sobre la pantalla real
                </h2>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    El recorrido navega por ti y resalta cada parte. Puedes
                    saltar, regresar o salir cuando quieras; no modifica nada.
                </p>
            </header>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="r in recorridos"
                    :key="r.id"
                    class="flex flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-crema text-bronce"
                        >
                            <Route class="size-4" />
                        </span>
                        <div class="min-w-0">
                            <p class="flex items-center gap-1.5 font-semibold">
                                {{ r.titulo }}
                                <CheckCircle2
                                    v-if="haVisto(`proceso-${r.id}`)"
                                    class="size-3.5 text-success"
                                    aria-label="Ya visto"
                                />
                            </p>
                            <p class="text-sm text-[var(--mrl-texto-suave)]">
                                {{ r.descripcion }}
                            </p>
                        </div>
                    </div>
                    <div class="mt-auto flex items-center gap-2">
                        <Button
                            size="sm"
                            class="gap-1.5"
                            @click="iniciar(tourDeRecorrido(r, contextoTour))"
                        >
                            <Compass class="size-3.5" /> Iniciar
                        </Button>
                        <Link
                            :href="`/ayuda/articulo/${r.articulo}`"
                            class="ml-auto text-xs text-[var(--mrl-texto-suave)] hover:underline"
                        >
                            Leer la guía
                        </Link>
                    </div>
                </div>
            </div>
        </section>

        <!-- Ayuda por pantalla -->
        <section class="space-y-5">
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Ayuda por pantalla
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Cada módulo de tu menú
                </h2>
            </header>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <details
                    v-for="m in modulos"
                    :key="m.id"
                    class="group rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 open:shadow-sm"
                >
                    <summary
                        class="flex cursor-pointer list-none items-start gap-3"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-salvia text-[var(--mrl-petroleo)]"
                        >
                            <component :is="m.icono" class="size-4" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="flex items-center gap-1.5 font-medium">
                                {{ m.nombre }}
                                <CheckCircle2
                                    v-if="haVisto(m.id)"
                                    class="size-3.5 text-success"
                                    aria-label="Ya visto"
                                />
                            </span>
                            <span
                                class="block text-sm text-pretty text-[var(--mrl-texto-suave)]"
                                >{{ m.descripcion }}</span
                            >
                        </span>
                    </summary>
                    <div
                        v-if="AYUDA_ESCRITA[m.id]"
                        class="mt-3 space-y-2 border-t border-[var(--mrl-borde)] pt-3 text-sm"
                    >
                        <p>{{ AYUDA_ESCRITA[m.id].queEs }}</p>
                        <ul
                            class="list-disc space-y-0.5 pl-5 text-[var(--mrl-texto-suave)]"
                        >
                            <li
                                v-for="punto in AYUDA_ESCRITA[m.id].puedes"
                                :key="punto"
                            >
                                {{ punto }}
                            </li>
                        </ul>
                        <p class="text-xs text-[var(--mrl-texto-suave)]">
                            <strong>Quién:</strong>
                            {{ AYUDA_ESCRITA[m.id].permisos }}
                        </p>
                    </div>
                    <div
                        class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1"
                    >
                        <button
                            type="button"
                            class="inline-flex items-center gap-1.5 text-sm font-medium text-[var(--mrl-petroleo)] hover:underline"
                            @click="iniciar(tourDeModulo(m, contextoTour))"
                        >
                            <Compass class="size-3.5" /> Recorrido
                        </button>
                        <Link
                            v-for="id in ARTICULOS_DE_MODULO[m.id] ?? []"
                            :key="id"
                            :href="`/ayuda/articulo/${id}`"
                            class="inline-flex items-center gap-1 text-xs text-[var(--mrl-texto-suave)] hover:underline"
                        >
                            <BookOpen class="size-3" />
                            {{ articulo(id)?.titulo }}
                        </Link>
                    </div>
                </details>
            </div>
        </section>

        <!-- Video, glosario y preguntas -->
        <section class="grid gap-4 lg:grid-cols-3">
            <Link
                v-if="flujoCompleto"
                href="/ayuda/video"
                class="flex tarjeta-interactiva flex-col gap-3 rounded-3xl border border-[var(--mrl-borde)] bg-gradient-to-br from-[var(--mrl-petroleo)] to-esmeralda p-5 text-white"
            >
                <CirclePlay class="size-8" />
                <p class="text-lg font-semibold">Tutorial en video</p>
                <p class="text-sm text-white/85">
                    El flujo completo de Recursos Humanos, por capítulos, más
                    videos cortos por tema.
                </p>
                <span
                    class="mt-auto flex items-center gap-1 text-sm font-semibold"
                >
                    {{ video ? 'Ver el video' : 'Ver capítulos y guion' }}
                    <ArrowRight class="size-4" />
                </span>
            </Link>
            <Link
                href="/ayuda/glosario"
                class="flex tarjeta-interactiva flex-col gap-3 rounded-3xl border border-[var(--mrl-borde)] bg-crema/60 p-5"
            >
                <GraduationCap class="size-8 text-bronce" />
                <p class="text-lg font-semibold">Glosario</p>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    Vacante real, plantilla autorizada, intervención,
                    onboarding, lote de nómina… en palabras sencillas.
                </p>
                <span
                    class="mt-auto flex items-center gap-1 text-sm font-semibold text-[var(--mrl-petroleo)]"
                >
                    Abrir el glosario <ArrowRight class="size-4" />
                </span>
            </Link>
            <div
                class="flex flex-col gap-2 rounded-3xl border border-[var(--mrl-borde)] bg-salvia/50 p-5"
            >
                <p class="text-lg font-semibold">Preguntas frecuentes</p>
                <Link
                    v-for="p in preguntas.slice(0, 4)"
                    :key="p.pregunta"
                    :href="
                        p.articulo
                            ? `/ayuda/articulo/${p.articulo}`
                            : '/ayuda/preguntas'
                    "
                    class="text-sm hover:underline"
                >
                    {{ p.pregunta }}
                </Link>
                <Link
                    href="/ayuda/preguntas"
                    class="mt-auto flex items-center gap-1 pt-2 text-sm font-semibold text-[var(--mrl-petroleo)]"
                >
                    Ver todas <ArrowRight class="size-4" />
                </Link>
            </div>
        </section>
    </div>
</template>
