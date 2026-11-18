<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    BookOpen,
    Clapperboard,
    Download,
    ExternalLink,
    FileText,
    Play,
    Users,
} from '@lucide/vue';
import { computed, nextTick, reactive, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { ayuda } from '@/routes';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Ayuda', href: '/ayuda' },
            { title: 'Material de apoyo', href: '/ayuda/material' },
        ],
    },
});

interface Material {
    slug: string;
    titulo: string;
    descripcion: string;
    tipo: 'video' | 'pdf';
    tipoEtiqueta: string;
    tema: string;
    temaEtiqueta: string;
    audiencia: string;
    bytes: number;
    url: string;
    descargar: string;
}

const props = defineProps<{
    /** Solo lo publicado con `php artisan ayuda:publicar-material`. */
    materiales: Material[];
    temas: Record<string, string>;
}>();

const videos = computed(() =>
    props.materiales.filter((m) => m.tipo === 'video'),
);
const documentos = computed(() =>
    props.materiales.filter((m) => m.tipo === 'pdf'),
);

/** `?material=slug` abre ese material directamente (desde el botón de ayuda). */
const inicial =
    typeof window !== 'undefined'
        ? new URLSearchParams(window.location.search).get('material')
        : null;
const materialInicial = props.materiales.find((m) => m.slug === inicial);

const videoActivo = ref<string | null>(
    materialInicial?.tipo === 'video'
        ? materialInicial.slug
        : (videos.value[0]?.slug ?? null),
);
const documentoActivo = ref<string | null>(
    materialInicial?.tipo === 'pdf'
        ? materialInicial.slug
        : (documentos.value[0]?.slug ?? null),
);

const video = computed(() =>
    videos.value.find((v) => v.slug === videoActivo.value),
);
const documento = computed(() =>
    documentos.value.find((d) => d.slug === documentoActivo.value),
);

const reproductor = ref<HTMLVideoElement | null>(null);
const seccionVideos = ref<HTMLElement | null>(null);
const seccionDocumentos = ref<HTMLElement | null>(null);

/** Duración real de cada video, leída de sus metadatos. */
const duraciones = reactive<Record<string, number>>({});

function registrarDuracion(slug: string, evento: Event): void {
    const elemento = evento.target as HTMLVideoElement;

    if (Number.isFinite(elemento.duration)) {
        duraciones[slug] = elemento.duration;
    }
}

function formatoDuracion(segundos: number | undefined): string {
    if (segundos === undefined) {
        return '';
    }

    const total = Math.round(segundos);

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
}

function formatoTamano(bytes: number): string {
    const mb = bytes / 1048576;

    return mb >= 1
        ? `${mb.toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function documentoDelTema(tema: string): Material | undefined {
    return documentos.value.find((d) => d.tema === tema);
}

function videoDelTema(tema: string): Material | undefined {
    return videos.value.find((v) => v.tema === tema);
}

async function verVideo(slug: string, reproducir = true): Promise<void> {
    videoActivo.value = slug;
    await nextTick();
    seccionVideos.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });

    if (reproducir) {
        void reproductor.value?.play().catch(() => undefined);
    }
}

function verDocumentoDelTema(tema: string): void {
    const d = documentoDelTema(tema);

    if (d) {
        void verDocumento(d.slug);
    }
}

function verVideoDelTema(tema: string): void {
    const v = videoDelTema(tema);

    if (v) {
        void verVideo(v.slug);
    }
}

async function verDocumento(slug: string): Promise<void> {
    documentoActivo.value = slug;
    await nextTick();
    seccionDocumentos.value?.scrollIntoView({
        behavior: 'smooth',
        block: 'start',
    });
}

/**
 * Accesos rápidos con las palabras del menú de Recursos Humanos: Manual de
 * operaciones, Videos, Flujograma de despliegue, Infografía de gerentes e
 * Infografía de gestor. Solo los que tienen algo publicado.
 */
const accesos = computed(() => {
    const lista: { clave: string; etiqueta: string; ir: () => void }[] = [];
    const doc = (tema: string, etiqueta: string): void => {
        const d = documentoDelTema(tema);

        if (d) {
            lista.push({
                clave: d.slug,
                etiqueta,
                ir: () => void verDocumento(d.slug),
            });
        }
    };

    doc('manual', props.temas.manual ?? 'Manual de operaciones');

    if (videos.value.length) {
        lista.push({
            clave: 'videos',
            etiqueta: 'Videos',
            ir: () =>
                seccionVideos.value?.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start',
                }),
        });
    }

    doc('flujograma', props.temas.flujograma ?? 'Flujograma de despliegue');
    doc('gerentes', props.temas.gerentes ?? 'Infografía de gerentes');
    doc('gestor', props.temas.gestor ?? 'Infografía de gestor');

    return lista;
});
</script>

<template>
    <Head title="Material de apoyo" />

    <div class="pagina-ancha flex flex-col gap-10">
        <!-- Encabezado -->
        <section
            class="relative overflow-hidden rounded-[2rem] border border-[var(--mrl-borde)] bg-gradient-to-br from-crema via-[var(--mrl-superficie)] to-salvia/70 px-5 py-8 sm:px-10"
        >
            <div class="flex flex-col gap-4">
                <Link
                    :href="ayuda.url()"
                    class="inline-flex w-fit items-center gap-1.5 enlace-animado text-xs font-medium text-[var(--mrl-texto-suave)]"
                >
                    <ArrowLeft class="size-3.5" /> Volver a la guía
                </Link>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Material de apoyo
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-[var(--mrl-texto)] sm:text-4xl"
                >
                    Videos, manual e infografías de MR. LANA PEOPLE
                </h1>
                <p
                    class="max-w-3xl text-base text-pretty text-[var(--mrl-texto-suave)]"
                >
                    El material oficial para aprender a usar PEOPLE: míralo aquí
                    mismo o descárgalo para compartirlo con tu equipo.
                </p>
                <div v-if="accesos.length" class="flex flex-wrap gap-2 pt-1">
                    <Button
                        v-for="a in accesos"
                        :key="a.clave"
                        variant="outline"
                        size="sm"
                        class="rounded-full bg-[var(--mrl-superficie)]"
                        @click="a.ir()"
                    >
                        {{ a.etiqueta }}
                    </Button>
                </div>
            </div>
        </section>

        <!-- Sin material publicado -->
        <section
            v-if="!materiales.length"
            class="flex flex-col items-center gap-3 rounded-3xl border border-dashed border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-6 py-16 text-center"
        >
            <span
                class="flex size-14 items-center justify-center rounded-2xl bg-crema text-bronce"
            >
                <Clapperboard class="size-7" />
            </span>
            <p class="text-lg font-semibold">
                El material de apoyo aún no está publicado en este servidor
            </p>
            <p class="max-w-lg text-sm text-[var(--mrl-texto-suave)]">
                Pídele al super administrador que lo publique con
                <code
                    class="rounded bg-crema px-1.5 py-0.5 text-xs font-semibold"
                    >php artisan ayuda:publicar-material</code
                >. Mientras tanto, la guía y los recorridos guiados siguen
                disponibles.
            </p>
            <Button variant="outline" as-child>
                <Link :href="ayuda.url()">
                    <BookOpen class="size-4" /> Ir a la guía
                </Link>
            </Button>
        </section>

        <!-- Videos -->
        <section
            v-if="videos.length"
            ref="seccionVideos"
            class="scroll-mt-24 space-y-5"
        >
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Videos
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Aprende en pocos minutos
                </h2>
            </header>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
                <!-- Reproductor -->
                <div v-if="video" class="flex flex-col gap-4">
                    <div
                        class="overflow-hidden rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-petroleo)] shadow-sm"
                    >
                        <video
                            ref="reproductor"
                            :key="video.slug"
                            :src="video.url"
                            controls
                            playsinline
                            preload="metadata"
                            class="aspect-video w-full bg-black"
                            @loadedmetadata="
                                registrarDuracion(video.slug, $event)
                            "
                        />
                    </div>
                    <div class="flex flex-wrap items-start gap-4">
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <h3 class="text-xl font-semibold">
                                {{ video.titulo }}
                            </h3>
                            <p
                                class="text-sm text-pretty text-[var(--mrl-texto-suave)]"
                            >
                                {{ video.descripcion }}
                            </p>
                            <p
                                class="flex flex-wrap items-center gap-2 text-xs text-[var(--mrl-texto-suave)]"
                            >
                                <Badge
                                    variant="secondary"
                                    class="gap-1 bg-salvia/70 text-[var(--mrl-texto)]"
                                >
                                    <Users class="size-3" />
                                    {{ video.audiencia }}
                                </Badge>
                                <span v-if="duraciones[video.slug]">
                                    {{
                                        formatoDuracion(duraciones[video.slug])
                                    }}
                                    min
                                </span>
                                <span>· {{ formatoTamano(video.bytes) }}</span>
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-if="documentoDelTema(video.tema)"
                                variant="outline"
                                size="sm"
                                class="gap-1.5"
                                @click="verDocumentoDelTema(video.tema)"
                            >
                                <FileText class="size-3.5" /> Ver el documento
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                class="gap-1.5"
                                as-child
                            >
                                <a :href="video.descargar">
                                    <Download class="size-3.5" /> Descargar
                                </a>
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Lista de videos -->
                <ol class="flex flex-col gap-3">
                    <li v-for="(v, i) in videos" :key="v.slug">
                        <button
                            type="button"
                            :class="[
                                'flex w-full tarjeta-interactiva items-center gap-3 rounded-2xl border p-2.5 text-left',
                                v.slug === videoActivo
                                    ? 'border-oro/60 bg-crema/70 shadow-sm'
                                    : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]',
                            ]"
                            :aria-current="v.slug === videoActivo || undefined"
                            @click="verVideo(v.slug)"
                        >
                            <span
                                class="relative aspect-video w-32 shrink-0 overflow-hidden rounded-xl bg-[var(--mrl-petroleo)]"
                            >
                                <!-- Cuadro del video como portada; solo metadatos. -->
                                <video
                                    :src="`${v.url}#t=4`"
                                    muted
                                    playsinline
                                    preload="metadata"
                                    tabindex="-1"
                                    aria-hidden="true"
                                    class="pointer-events-none size-full object-cover"
                                    @loadedmetadata="
                                        registrarDuracion(v.slug, $event)
                                    "
                                />
                                <span
                                    class="absolute inset-0 flex items-center justify-center bg-black/25"
                                >
                                    <span
                                        class="flex size-8 items-center justify-center rounded-full bg-white/90 text-[var(--mrl-petroleo)] shadow"
                                    >
                                        <Play
                                            class="ml-0.5 size-4 fill-current"
                                        />
                                    </span>
                                </span>
                                <span
                                    v-if="duraciones[v.slug]"
                                    class="absolute right-1 bottom-1 rounded bg-black/70 px-1.5 py-0.5 text-[10px] font-semibold text-white tabular-nums"
                                >
                                    {{ formatoDuracion(duraciones[v.slug]) }}
                                </span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block text-[11px] font-semibold tracking-wide text-[var(--mrl-texto-suave)] uppercase"
                                >
                                    Video {{ i + 1 }}
                                </span>
                                <span
                                    class="line-clamp-2 text-sm font-semibold"
                                    >{{ v.titulo }}</span
                                >
                                <span
                                    class="block truncate text-xs text-[var(--mrl-texto-suave)]"
                                    >{{ v.audiencia }}</span
                                >
                            </span>
                        </button>
                    </li>
                </ol>
            </div>
        </section>

        <!-- Documentos -->
        <section
            v-if="documentos.length"
            ref="seccionDocumentos"
            class="scroll-mt-24 space-y-5"
        >
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Documentos
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Manual, flujograma e infografías
                </h2>
            </header>

            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <button
                    v-for="d in documentos"
                    :key="d.slug"
                    type="button"
                    :class="[
                        'flex tarjeta-interactiva flex-col gap-3 rounded-2xl border p-4 text-left',
                        d.slug === documentoActivo
                            ? 'border-oro/60 bg-crema/70 shadow-sm'
                            : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]',
                    ]"
                    :aria-current="d.slug === documentoActivo || undefined"
                    @click="verDocumento(d.slug)"
                >
                    <span class="flex items-start gap-3">
                        <span
                            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-crema to-salvia text-[var(--mrl-petroleo)]"
                        >
                            <FileText class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold">{{
                                d.titulo
                            }}</span>
                            <span class="text-xs text-[var(--mrl-texto-suave)]"
                                >PDF · {{ formatoTamano(d.bytes) }}</span
                            >
                        </span>
                    </span>
                    <span
                        class="line-clamp-3 text-sm text-pretty text-[var(--mrl-texto-suave)]"
                        >{{ d.descripcion }}</span
                    >
                    <Badge
                        variant="secondary"
                        class="mt-auto w-fit gap-1 bg-salvia/70 text-[11px] text-[var(--mrl-texto)]"
                    >
                        <Users class="size-3" /> {{ d.audiencia }}
                    </Badge>
                </button>
            </div>

            <!-- Visor -->
            <div
                v-if="documento"
                class="overflow-hidden rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] shadow-sm"
            >
                <div
                    class="flex flex-wrap items-center gap-3 border-b border-[var(--mrl-borde)] px-4 py-3"
                >
                    <FileText class="size-4 text-bronce" />
                    <p class="min-w-0 flex-1 truncate font-semibold">
                        {{ documento.titulo }}
                    </p>
                    <Button
                        v-if="videoDelTema(documento.tema)"
                        variant="ghost"
                        size="sm"
                        class="gap-1.5"
                        @click="verVideoDelTema(documento.tema)"
                    >
                        <Play class="size-3.5" /> Ver el video
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        class="gap-1.5"
                        as-child
                    >
                        <a :href="documento.url" target="_blank" rel="noopener">
                            <ExternalLink class="size-3.5" /> Abrir en otra
                            pestaña
                        </a>
                    </Button>
                    <Button size="sm" class="gap-1.5" as-child>
                        <a :href="documento.descargar">
                            <Download class="size-3.5" /> Descargar
                        </a>
                    </Button>
                </div>
                <iframe
                    :key="documento.slug"
                    :src="documento.url"
                    :title="documento.titulo"
                    class="h-[80vh] min-h-[28rem] w-full bg-crema/40"
                />
            </div>
        </section>
    </div>
</template>
