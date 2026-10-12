<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    ArrowRight,
    BellRing,
    BookOpen,
    CheckCircle2,
    ClipboardList,
    Compass,
    FileText,
    History,
    Info,
    Lightbulb,
    ListChecks,
    ShieldAlert,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import GuiaBuscador from '@/components/guia/GuiaBuscador.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ETIQUETA_CANAL, useGuia } from '@/composables/useGuia';
import { useTourGuiado } from '@/composables/useTourGuiado';
import {
    articulo as buscarArticulo,
    NOMBRE_ROL,
    procesoDeArticulo,
    vecinos,
} from '@/lib/guia';
import { iconoGuia } from '@/lib/guia/iconos';
import { RECORRIDOS_PROCESO, tourDeRecorrido } from '@/lib/tours/recorridos';

const props = defineProps<{
    articulo: string;
    capturas: string[];
}>();

const { accesos, contextoTour, modo } = useGuia();
const { iniciar } = useTourGuiado();

const art = computed(() => buscarArticulo(props.articulo));
const proc = computed(() =>
    art.value ? procesoDeArticulo(art.value) : undefined,
);
const nav = computed(() => (art.value ? vecinos(art.value) : {}));
const botones = computed(() => (art.value ? accesos(art.value.accesos) : []));
const recorrido = computed(() =>
    RECORRIDOS_PROCESO.find(
        (r) =>
            r.id === art.value?.recorrido &&
            r.modo === modo.value &&
            (r.permisos.length === 0 ||
                r.permisos.some((p) => contextoTour.value.tienePermiso(p))),
    ),
);

const TONO_LLAMADO = {
    info: { clase: 'border-esmeralda/30 bg-salvia/50', icono: Info },
    aviso: {
        clase: 'border-oro/40 bg-[var(--pastel-warning)]/50',
        icono: AlertTriangle,
    },
    importante: {
        clase: 'border-[var(--mrl-petroleo)]/30 bg-crema/70',
        icono: ShieldAlert,
    },
} as const;

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});
</script>

<template>
    <Head :title="art?.titulo ?? 'Guía'" />

    <div class="pagina-media flex flex-col gap-8">
        <template v-if="art">
            <!-- Migas y encabezado -->
            <nav
                class="flex flex-wrap items-center gap-1.5 text-xs text-[var(--mrl-texto-suave)]"
                aria-label="Ubicación en la guía"
            >
                <Link href="/ayuda" class="hover:underline">Guía</Link>
                <span>›</span>
                <Link
                    v-if="proc"
                    :href="`/ayuda/proceso/${proc.id}`"
                    class="hover:underline"
                >
                    {{ proc.numero }}. {{ proc.titulo }}
                </Link>
                <span>›</span>
                <span class="text-[var(--mrl-texto)]">{{ art.titulo }}</span>
            </nav>

            <header
                class="grid gap-6 rounded-[2rem] border border-[var(--mrl-borde)] bg-gradient-to-br from-crema/80 via-[var(--mrl-superficie)] to-salvia/50 p-6 sm:p-8 lg:grid-cols-[1fr_auto]"
            >
                <div class="flex items-start gap-4">
                    <span
                        v-if="proc"
                        class="hidden size-14 shrink-0 items-center justify-center rounded-2xl bg-[var(--mrl-superficie)] text-[var(--mrl-petroleo)] shadow-sm sm:flex"
                    >
                        <component :is="iconoGuia(proc.icono)" class="size-7" />
                    </span>
                    <div class="min-w-0 space-y-2">
                        <h1
                            class="text-2xl font-semibold tracking-tight text-pretty sm:text-3xl"
                        >
                            {{ art.titulo }}
                        </h1>
                        <p class="text-pretty text-[var(--mrl-texto-suave)]">
                            {{ art.resumen }}
                        </p>
                        <div class="flex flex-wrap gap-1.5 pt-1">
                            <Badge
                                v-for="r in art.quien"
                                :key="r"
                                variant="secondary"
                                class="bg-salvia text-[var(--mrl-texto)]"
                            >
                                {{ NOMBRE_ROL[r] }}
                            </Badge>
                        </div>
                    </div>
                </div>
                <div
                    v-if="botones.length || recorrido"
                    class="flex flex-col gap-2 lg:min-w-56"
                >
                    <Button
                        v-for="a in botones"
                        :key="a.ruta"
                        as-child
                        class="justify-between gap-2"
                    >
                        <Link :href="a.ruta"
                            >{{ a.etiqueta }} <ArrowRight class="size-4"
                        /></Link>
                    </Button>
                    <Button
                        v-if="recorrido"
                        variant="outline"
                        class="gap-2 bg-[var(--mrl-superficie)]"
                        @click="
                            iniciar(tourDeRecorrido(recorrido, contextoTour))
                        "
                    >
                        <Compass class="size-4" /> Recorrido guiado
                    </Button>
                </div>
            </header>

            <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <article class="flex min-w-0 flex-col gap-8">
                    <!-- QUÉ ES -->
                    <section>
                        <h2
                            class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            <BookOpen class="size-4" /> Qué es
                        </h2>
                        <p class="text-[15px] leading-relaxed text-pretty">
                            {{ art.queEs }}
                        </p>
                    </section>

                    <!-- QUIÉN LO HACE -->
                    <section>
                        <h2
                            class="mb-2 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            <Users class="size-4" /> Quién lo hace
                        </h2>
                        <p class="text-[15px] text-pretty">
                            {{
                                art.quienDetalle ??
                                art.quien.map((r) => NOMBRE_ROL[r]).join(', ') +
                                    '.'
                            }}
                        </p>
                    </section>

                    <!-- Llamados -->
                    <div
                        v-if="art.llamados?.length"
                        class="flex flex-col gap-3"
                    >
                        <div
                            v-for="l in art.llamados"
                            :key="l.texto"
                            :class="[
                                'flex items-start gap-3 rounded-2xl border p-4 text-sm',
                                TONO_LLAMADO[l.tono].clase,
                            ]"
                        >
                            <component
                                :is="TONO_LLAMADO[l.tono].icono"
                                class="mt-0.5 size-4 shrink-0"
                            />
                            <p class="text-pretty">{{ l.texto }}</p>
                        </div>
                    </div>

                    <!-- ANTES DE EMPEZAR -->
                    <section v-if="art.antes.length">
                        <h2
                            class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            <ListChecks class="size-4" /> Antes de empezar
                        </h2>
                        <ul class="grid gap-2">
                            <li
                                v-for="a in art.antes"
                                :key="a"
                                class="flex items-start gap-2 rounded-xl bg-[var(--mrl-superficie)] px-3 py-2 text-sm ring-1 ring-[var(--mrl-borde)]"
                            >
                                <CheckCircle2
                                    class="mt-0.5 size-4 shrink-0 text-esmeralda"
                                />
                                {{ a }}
                            </li>
                        </ul>
                    </section>

                    <!-- PASOS -->
                    <section>
                        <h2
                            class="mb-4 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            <ClipboardList class="size-4" /> Pasos
                        </h2>
                        <ol
                            class="relative flex flex-col gap-4 border-l-2 border-dashed border-oro/40 pl-6"
                        >
                            <li
                                v-for="(paso, i) in art.pasos"
                                :key="paso.titulo"
                                class="relative"
                            >
                                <span
                                    class="absolute top-0 -left-[2.15rem] flex size-7 items-center justify-center rounded-full bg-[var(--mrl-petroleo)] text-xs font-semibold text-white tabular-nums ring-4 ring-[var(--mrl-fondo)]"
                                >
                                    {{ i + 1 }}
                                </span>
                                <div
                                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                                >
                                    <div
                                        class="mb-1 flex flex-wrap items-center gap-2"
                                    >
                                        <p class="font-semibold">
                                            {{ paso.titulo }}
                                        </p>
                                        <Badge
                                            v-if="paso.quien"
                                            variant="secondary"
                                            class="bg-crema text-[11px] text-[var(--mrl-texto)]"
                                        >
                                            {{ NOMBRE_ROL[paso.quien] }}
                                        </Badge>
                                        <Badge
                                            v-if="paso.canal"
                                            variant="outline"
                                            class="text-[11px]"
                                        >
                                            {{ ETIQUETA_CANAL[paso.canal] }}
                                        </Badge>
                                    </div>
                                    <p
                                        class="text-sm text-pretty text-[var(--mrl-texto-suave)]"
                                    >
                                        {{ paso.detalle }}
                                    </p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <!-- Campos -->
                    <section v-if="art.campos?.length">
                        <h2
                            class="mb-3 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            Lo que se captura
                        </h2>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="g in art.campos"
                                :key="g.grupo"
                                class="rounded-2xl bg-crema/40 p-4 ring-1 ring-[var(--mrl-borde)]"
                            >
                                <p class="mb-2 text-sm font-semibold">
                                    {{ g.grupo }}
                                </p>
                                <div class="flex flex-wrap gap-1.5">
                                    <span
                                        v-for="c in g.campos"
                                        :key="c"
                                        class="rounded-full bg-[var(--mrl-superficie)] px-2.5 py-0.5 text-xs ring-1 ring-[var(--mrl-borde)]"
                                    >
                                        {{ c }}
                                    </span>
                                </div>
                            </div>
                        </div>
                    </section>

                    <!-- Capturas reales -->
                    <section v-if="capturas.length">
                        <h2
                            class="mb-3 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            Así se ve en el sistema
                        </h2>
                        <figure
                            v-for="(src, i) in capturas"
                            :key="src"
                            class="mb-4 overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] shadow-sm"
                        >
                            <img
                                :src="src"
                                :alt="`${art.titulo} — captura ${i + 1}`"
                                loading="lazy"
                                class="w-full"
                            />
                            <figcaption
                                class="px-4 py-2 text-xs text-[var(--mrl-texto-suave)]"
                            >
                                Captura del sistema con datos ficticios de
                                demostración.
                            </figcaption>
                        </figure>
                    </section>

                    <!-- QUÉ PASA DESPUÉS -->
                    <section v-if="art.despues.length">
                        <h2
                            class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            <ArrowRight class="size-4" /> Qué pasa después
                        </h2>
                        <ul class="grid gap-2">
                            <li
                                v-for="d in art.despues"
                                :key="d"
                                class="flex items-start gap-2 rounded-xl bg-salvia/40 px-3 py-2 text-sm"
                            >
                                <ArrowRight
                                    class="mt-0.5 size-4 shrink-0 text-[var(--mrl-petroleo)]"
                                />
                                {{ d }}
                            </li>
                        </ul>
                    </section>

                    <!-- ERRORES COMUNES -->
                    <section v-if="art.errores.length">
                        <h2
                            class="mb-3 flex items-center gap-2 text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                        >
                            <Lightbulb class="size-4" /> Errores comunes
                        </h2>
                        <div class="grid gap-3">
                            <div
                                v-for="e in art.errores"
                                :key="e.problema"
                                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                            >
                                <p class="font-medium">{{ e.problema }}</p>
                                <p
                                    class="mt-1 text-sm text-pretty text-[var(--mrl-texto-suave)]"
                                >
                                    {{ e.solucion }}
                                </p>
                            </div>
                        </div>
                    </section>

                    <!-- Anterior / siguiente -->
                    <nav
                        class="grid gap-3 border-t border-[var(--mrl-borde)] pt-6 sm:grid-cols-2"
                        aria-label="Artículos del proceso"
                    >
                        <Link
                            v-if="nav.anterior"
                            :href="`/ayuda/articulo/${nav.anterior.id}`"
                            class="tarjeta-interactiva rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                        >
                            <span
                                class="flex items-center gap-1 text-xs text-[var(--mrl-texto-suave)]"
                                ><ArrowLeft class="size-3.5" /> Anterior</span
                            >
                            <span class="font-medium">{{
                                nav.anterior.titulo
                            }}</span>
                        </Link>
                        <span v-else />
                        <Link
                            v-if="nav.siguiente"
                            :href="`/ayuda/articulo/${nav.siguiente.id}`"
                            class="tarjeta-interactiva rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-right"
                        >
                            <span
                                class="flex items-center justify-end gap-1 text-xs text-[var(--mrl-texto-suave)]"
                                >Siguiente <ArrowRight class="size-3.5"
                            /></span>
                            <span class="font-medium">{{
                                nav.siguiente.titulo
                            }}</span>
                        </Link>
                    </nav>
                </article>

                <!-- Columna lateral -->
                <aside
                    class="flex flex-col gap-4 lg:sticky lg:top-24 lg:self-start"
                >
                    <div
                        v-if="art.notificaciones?.length"
                        class="rounded-2xl bg-[var(--mrl-superficie)] p-4 ring-1 ring-[var(--mrl-borde)]"
                    >
                        <p
                            class="mb-2 flex items-center gap-2 text-sm font-semibold"
                        >
                            <BellRing class="size-4 text-oro" /> Notificaciones
                        </p>
                        <ul
                            class="space-y-1.5 text-sm text-[var(--mrl-texto-suave)]"
                        >
                            <li v-for="n in art.notificaciones" :key="n">
                                {{ n }}
                            </li>
                        </ul>
                    </div>
                    <div
                        v-if="art.documentos?.length"
                        class="rounded-2xl bg-[var(--mrl-superficie)] p-4 ring-1 ring-[var(--mrl-borde)]"
                    >
                        <p
                            class="mb-2 flex items-center gap-2 text-sm font-semibold"
                        >
                            <FileText class="size-4 text-oro" /> Documentos que
                            se generan
                        </p>
                        <ul
                            class="space-y-1.5 text-sm text-[var(--mrl-texto-suave)]"
                        >
                            <li v-for="d in art.documentos" :key="d">
                                {{ d }}
                            </li>
                        </ul>
                    </div>
                    <div
                        v-if="art.registro?.length"
                        class="rounded-2xl bg-[var(--mrl-superficie)] p-4 ring-1 ring-[var(--mrl-borde)]"
                    >
                        <p
                            class="mb-2 flex items-center gap-2 text-sm font-semibold"
                        >
                            <History class="size-4 text-oro" /> Qué queda
                            registrado
                        </p>
                        <ul
                            class="space-y-1.5 text-sm text-[var(--mrl-texto-suave)]"
                        >
                            <li v-for="r in art.registro" :key="r">{{ r }}</li>
                        </ul>
                    </div>
                    <div
                        v-if="art.relacionados.length"
                        class="rounded-2xl bg-crema/50 p-4"
                    >
                        <p class="mb-2 text-sm font-semibold">
                            Relacionado con
                        </p>
                        <ul class="space-y-1.5">
                            <li v-for="id in art.relacionados" :key="id">
                                <Link
                                    :href="`/ayuda/articulo/${id}`"
                                    class="text-sm text-[var(--mrl-petroleo)] hover:underline"
                                >
                                    {{ buscarArticulo(id)?.titulo }}
                                </Link>
                            </li>
                        </ul>
                    </div>
                    <div
                        class="rounded-2xl bg-[var(--mrl-superficie)] p-4 ring-1 ring-[var(--mrl-borde)]"
                    >
                        <p class="mb-2 text-sm font-semibold">¿Otra duda?</p>
                        <GuiaBuscador />
                    </div>
                </aside>
            </div>
        </template>

        <div
            v-else
            class="mx-auto flex max-w-md flex-col items-center gap-4 py-20 text-center"
        >
            <BookOpen class="size-10 text-oro" />
            <h1 class="text-xl font-semibold">No encontré ese artículo</h1>
            <p class="text-sm text-[var(--mrl-texto-suave)]">
                Puede que el enlace sea antiguo. Búscalo con tus palabras:
            </p>
            <GuiaBuscador />
            <Button as-child variant="outline"
                ><Link href="/ayuda">Volver a la guía</Link></Button
            >
        </div>
    </div>
</template>
