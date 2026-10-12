<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { BookOpen, CirclePlay, Clapperboard, Clock, Film } from '@lucide/vue';
import { computed, ref } from 'vue';
import { articulo } from '@/lib/guia';
import {
    capitulosVideo,
    escenasVideo,
    formatoTiempo,
    MINI_VIDEOS,
    TITULO_VIDEO,
} from '@/lib/guia/video';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Ayuda', href: '/ayuda' },
            { title: 'Tutorial en video', href: '/ayuda/video' },
        ],
    },
});

const props = defineProps<{
    video: {
        url: string;
        subtitulos: string | null;
        miniatura: string | null;
    } | null;
    /** Videos cortos ya producidos: id → URL. */
    cortos: Record<string, string>;
}>();

const reproductor = ref<HTMLVideoElement | null>(null);
const escenas = escenasVideo();
const capitulos = capitulosVideo();
const duracion = formatoTiempo(escenas.at(-1)?.fin ?? 0);
const capituloAbierto = ref<string | null>(capitulos[0]?.titulo ?? null);

const escenasDe = computed(
    () => (titulo: string) => escenas.filter((e) => e.capitulo === titulo),
);

function irA(segundos: number): void {
    if (props.video && reproductor.value) {
        reproductor.value.currentTime = segundos;
        void reproductor.value.play();
        reproductor.value.scrollIntoView({
            behavior: 'smooth',
            block: 'center',
        });
    }
}
</script>

<template>
    <Head title="Tutorial en video" />

    <div class="pagina-media flex flex-col gap-8">
        <header class="space-y-2">
            <p
                class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
            >
                Tutorial en video
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">
                {{ TITULO_VIDEO }}
            </h1>
            <p
                class="flex items-center gap-1.5 text-sm text-[var(--mrl-texto-suave)]"
            >
                <Clock class="size-4" /> {{ duracion }} ·
                {{ capitulos.length }} capítulos · con subtítulos
            </p>
        </header>

        <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
            <div class="flex flex-col gap-4">
                <div
                    class="overflow-hidden rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-petroleo)] shadow-sm"
                >
                    <video
                        v-if="video"
                        ref="reproductor"
                        :src="video.url"
                        :poster="video.miniatura ?? undefined"
                        controls
                        preload="metadata"
                        class="aspect-video w-full bg-black"
                    >
                        <track
                            v-if="video.subtitulos"
                            kind="subtitles"
                            srclang="es"
                            label="Español"
                            :src="video.subtitulos"
                            default
                        />
                    </video>
                    <div
                        v-else
                        class="flex aspect-video flex-col items-center justify-center gap-3 bg-gradient-to-br from-[var(--mrl-petroleo)] to-esmeralda px-6 text-center text-white"
                    >
                        <Clapperboard class="size-12 opacity-90" />
                        <p class="text-lg font-semibold">
                            El video aún no está publicado en este servidor
                        </p>
                        <p class="max-w-md text-sm text-white/85">
                            Mientras tanto, aquí tienes cada capítulo con lo que
                            se ve en pantalla y lo que se narra, y su artículo
                            de la guía.
                        </p>
                    </div>
                </div>

                <!-- Escenas del capítulo abierto -->
                <section
                    v-for="cap in capitulos"
                    v-show="capituloAbierto === cap.titulo"
                    :key="cap.titulo"
                    class="flex flex-col gap-3"
                >
                    <h2 class="text-xl font-semibold">{{ cap.titulo }}</h2>
                    <article
                        v-for="e in escenasDe(cap.titulo)"
                        :key="e.numero"
                        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                    >
                        <p
                            class="mb-1 flex flex-wrap items-center gap-2 text-xs text-[var(--mrl-texto-suave)]"
                        >
                            <span
                                class="rounded-full bg-crema px-2 py-0.5 font-semibold text-bronce tabular-nums"
                                >{{ formatoTiempo(e.inicio) }}</span
                            >
                            Escena {{ e.numero }} · {{ e.pantalla }}
                        </p>
                        <p class="text-sm font-medium">{{ e.textoPantalla }}</p>
                        <p
                            class="mt-2 text-sm text-pretty text-[var(--mrl-texto-suave)] italic"
                        >
                            «{{ e.narracion }}»
                        </p>
                    </article>
                </section>
            </div>

            <!-- Capítulos -->
            <aside
                class="flex flex-col gap-2 lg:sticky lg:top-24 lg:self-start"
            >
                <p
                    class="text-xs font-semibold tracking-[0.16em] text-bronce uppercase"
                >
                    Capítulos
                </p>
                <button
                    v-for="(cap, i) in capitulos"
                    :key="cap.titulo"
                    type="button"
                    :class="[
                        'flex items-center gap-3 rounded-xl px-3 py-2 text-left text-sm transition',
                        capituloAbierto === cap.titulo
                            ? 'bg-crema font-semibold'
                            : 'hover:bg-crema/50',
                    ]"
                    @click="
                        capituloAbierto = cap.titulo;
                        irA(cap.inicio);
                    "
                >
                    <span
                        class="w-11 shrink-0 text-xs text-[var(--mrl-texto-suave)] tabular-nums"
                        >{{ formatoTiempo(cap.inicio) }}</span
                    >
                    <span class="min-w-0 flex-1"
                        >{{ i + 1 }}. {{ cap.titulo }}</span
                    >
                </button>
            </aside>
        </div>

        <!-- Mini videos -->
        <section class="space-y-4">
            <header>
                <p
                    class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                >
                    Videos cortos
                </p>
                <h2 class="text-2xl font-semibold tracking-tight">
                    Un tema, de 1 a 3 minutos
                </h2>
            </header>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <details
                    v-for="mini in MINI_VIDEOS"
                    :key="mini.id"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 open:shadow-sm"
                >
                    <summary
                        class="flex cursor-pointer list-none items-start gap-3"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-salvia text-[var(--mrl-petroleo)]"
                        >
                            <Film class="size-4" />
                        </span>
                        <span>
                            <span class="block font-semibold">{{
                                mini.titulo
                            }}</span>
                            <span class="text-xs text-[var(--mrl-texto-suave)]"
                                >{{ mini.duracion }} ·
                                {{ mini.escenas.length }} escenas</span
                            >
                        </span>
                    </summary>
                    <video
                        v-if="cortos[mini.id]"
                        :src="cortos[mini.id]"
                        controls
                        preload="none"
                        class="mt-3 aspect-video w-full rounded-xl bg-black"
                    />
                    <ol class="mt-3 list-decimal space-y-2 pl-5 text-sm">
                        <li v-for="e in mini.escenas" :key="e.pantalla">
                            <span class="font-medium">{{ e.pantalla }}.</span>
                            <span class="text-[var(--mrl-texto-suave)]">
                                «{{ e.narracion }}»</span
                            >
                        </li>
                    </ol>
                    <Link
                        :href="`/ayuda/articulo/${mini.articulo}`"
                        class="mt-3 inline-flex items-center gap-1 text-xs text-[var(--mrl-petroleo)] hover:underline"
                    >
                        <BookOpen class="size-3" />
                        {{ articulo(mini.articulo)?.titulo }}
                    </Link>
                </details>
            </div>
        </section>

        <p
            class="flex items-center gap-2 text-xs text-[var(--mrl-texto-suave)]"
        >
            <CirclePlay class="size-4" />
            El video se graba con datos ficticios de demostración: ningún dato
            real de colaboradores aparece en pantalla. La voz actual es una
            locución sintética de referencia; la versión con locutor se publica
            en este mismo lugar.
        </p>
    </div>
</template>
