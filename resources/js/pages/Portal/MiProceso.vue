<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import {
    ArrowRight,
    CheckCircle2,
    Clock,
    Hourglass,
    Lock,
    PlayCircle,
    Sparkles,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';
import { evaluacion as evaluacionUrl } from '@/routes/portal/onboarding';
import type { LeccionBienvenida, MisPendientes } from '@/types';

const props = defineProps<{
    pendientes: MisPendientes['pendientes'];
    lecciones: MisPendientes['lecciones'];
    documentos: MisPendientes['documentos'];
    todo_listo: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: '' },
        ],
    },
});

const acciones = computed(() =>
    props.pendientes.filter((p) => p.tipo === 'accion'),
);
const esperas = computed(() =>
    props.pendientes.filter((p) => p.tipo === 'espera'),
);
const leccionesAprobadas = computed(
    () => props.lecciones.filter((l) => l.estado === 'aprobada').length,
);

const abierta = ref<number | null>(
    props.lecciones.find((l) => l.puede_presentar)?.avance_id ?? null,
);
const form = useForm<{ respuestas: Record<number, number> }>({
    respuestas: {},
});

function alternar(leccion: LeccionBienvenida) {
    if (leccion.estado === 'bloqueada') {
        return;
    }

    abierta.value =
        abierta.value === leccion.avance_id ? null : leccion.avance_id;
    form.reset();
    form.clearErrors();
}

function presentar(leccion: LeccionBienvenida) {
    form.post(evaluacionUrl.url(leccion.avance_id), {
        preserveScroll: true,
        onSuccess: () => {
            abierta.value = null;
            form.reset();
        },
    });
}

const estiloLeccion: Record<LeccionBienvenida['estado'], string> = {
    aprobada: 'border-[var(--mrl-success)]/30 bg-[var(--mrl-success)]/5',
    disponible:
        'border-[var(--mrl-primary)]/40 hover:border-[var(--mrl-primary)] hover:shadow-md',
    en_espera: 'border-[var(--mrl-gold)]/40 bg-[var(--mrl-gold)]/5',
    bloqueada: 'border-[var(--mrl-borde)] opacity-60',
};

const textoEstado: Record<LeccionBienvenida['estado'], string> = {
    aprobada: '¡Aprobada!',
    disponible: 'Lista para responder',
    en_espera:
        'Recursos Humanos te dará una retroalimentación para volver a intentarlo',
    bloqueada: 'Se abre al aprobar la anterior',
};

const claseBoton =
    'inline-flex shrink-0 items-center gap-2 rounded-xl bg-[var(--mrl-primary)] px-4 py-2.5 text-sm font-medium text-white transition-all hover:gap-3 hover:bg-[var(--mrl-primary-alt)] focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:ring-offset-2 focus-visible:outline-none';
</script>

<template>
    <Head title="Mis pendientes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <header>
            <h1
                class="text-2xl font-semibold tracking-tight text-[var(--mrl-texto)]"
            >
                Mis pendientes
            </h1>
            <p class="text-sm text-[var(--mrl-texto-suave)]">
                Lo que te toca hacer ahora. Recursos Humanos te irá indicando
                cada paso.
            </p>
        </header>

        <section
            v-if="todo_listo"
            class="flex items-center gap-4 rounded-3xl border border-[var(--mrl-success)]/30 bg-[var(--mrl-success)]/5 p-6"
        >
            <CheckCircle2 class="size-10 shrink-0 text-[var(--mrl-success)]" />
            <div>
                <p class="text-lg font-semibold text-[var(--mrl-texto)]">
                    ¡Estás al día!
                </p>
                <p class="text-sm text-[var(--mrl-texto-suave)]">
                    No tienes nada pendiente. Te avisaremos cuando haya algo
                    nuevo.
                </p>
            </div>
        </section>

        <div
            v-else
            class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]"
        >
            <div class="flex flex-col gap-4">
                <article
                    v-for="p in acciones"
                    :key="p.clave"
                    class="group flex flex-col gap-4 rounded-3xl border border-[var(--mrl-primary)]/30 bg-[var(--mrl-surface)] p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-[var(--mrl-primary)] hover:shadow-lg sm:flex-row sm:items-center"
                >
                    <span
                        class="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-[var(--mrl-primary)]/10 text-[var(--mrl-primary)] transition-colors group-hover:bg-[var(--mrl-primary)] group-hover:text-white"
                    >
                        <Sparkles class="size-6" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p
                            class="text-base font-semibold text-[var(--mrl-texto)]"
                        >
                            {{ p.titulo }}
                        </p>
                        <p class="text-sm text-[var(--mrl-texto-suave)]">
                            {{ p.descripcion }}
                        </p>
                        <ul
                            v-if="p.detalle.length"
                            class="mt-2 flex flex-wrap gap-1.5"
                        >
                            <li
                                v-for="d in p.detalle"
                                :key="d"
                                class="rounded-full bg-[var(--mrl-fondo)] px-2.5 py-0.5 text-xs text-[var(--mrl-texto)]"
                            >
                                {{ d }}
                            </li>
                        </ul>
                    </div>
                    <a
                        v-if="p.accion && p.accion.href.startsWith('#')"
                        :href="p.accion.href"
                        :class="claseBoton"
                    >
                        {{ p.accion.etiqueta }} <ArrowRight class="size-4" />
                    </a>
                    <Link
                        v-else-if="p.accion"
                        :href="p.accion.href"
                        :class="claseBoton"
                    >
                        {{ p.accion.etiqueta }} <ArrowRight class="size-4" />
                    </Link>
                </article>

                <section
                    v-if="lecciones.length"
                    id="lecciones"
                    class="flex scroll-mt-4 flex-col gap-3 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <h2
                            class="text-base font-semibold text-[var(--mrl-texto)]"
                        >
                            Bienvenida a MR. LANA
                        </h2>
                        <span
                            class="rounded-full bg-[var(--mrl-fondo)] px-3 py-1 text-xs font-medium text-[var(--mrl-texto)]"
                        >
                            {{ leccionesAprobadas }} de
                            {{ lecciones.length }} lecciones
                        </span>
                    </div>
                    <p class="text-sm text-[var(--mrl-texto-suave)]">
                        Revisa el material de cada lección y responde sus
                        preguntas. Se abren en orden.
                    </p>

                    <article
                        v-for="l in lecciones"
                        :key="l.avance_id"
                        class="rounded-2xl border p-4 transition-all duration-200"
                        :class="estiloLeccion[l.estado]"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 rounded-lg text-left focus-visible:ring-2 focus-visible:ring-[var(--mrl-primary)] focus-visible:outline-none disabled:cursor-not-allowed"
                            :disabled="l.estado === 'bloqueada'"
                            :aria-expanded="abierta === l.avance_id"
                            @click="alternar(l)"
                        >
                            <CheckCircle2
                                v-if="l.estado === 'aprobada'"
                                class="size-5 shrink-0 text-[var(--mrl-success)]"
                            />
                            <Lock
                                v-else-if="l.estado === 'bloqueada'"
                                class="size-5 shrink-0 text-[var(--mrl-muted)]"
                            />
                            <Hourglass
                                v-else-if="l.estado === 'en_espera'"
                                class="size-5 shrink-0 text-[var(--mrl-gold)]"
                            />
                            <PlayCircle
                                v-else
                                class="size-5 shrink-0 text-[var(--mrl-primary)]"
                            />
                            <span class="min-w-0 flex-1">
                                <span
                                    class="block font-medium text-[var(--mrl-texto)]"
                                    >{{ l.titulo }}</span
                                >
                                <span
                                    class="block text-xs text-[var(--mrl-texto-suave)]"
                                    >{{ textoEstado[l.estado] }}</span
                                >
                            </span>
                            <span
                                v-if="l.calificacion !== null"
                                class="text-sm font-semibold tabular-nums"
                                :class="
                                    l.estado === 'aprobada'
                                        ? 'text-[var(--mrl-success)]'
                                        : 'text-[var(--mrl-texto)]'
                                "
                            >
                                {{ l.calificacion.toFixed(1) }}
                            </span>
                        </button>

                        <div
                            v-if="abierta === l.avance_id"
                            class="mt-4 flex flex-col gap-4 border-t border-[var(--mrl-borde)] pt-4"
                        >
                            <p
                                v-if="l.retroalimentacion"
                                class="rounded-xl bg-[var(--mrl-gold)]/10 p-3 text-sm"
                            >
                                Comentario de Recursos Humanos:
                                {{ l.retroalimentacion }}
                            </p>
                            <p
                                v-if="l.descripcion"
                                class="text-sm text-[var(--mrl-texto-suave)]"
                            >
                                {{ l.descripcion }}
                            </p>
                            <a
                                v-if="l.contenido_url"
                                :href="l.contenido_url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex w-fit items-center gap-2 rounded-xl border border-[var(--mrl-primary)]/40 px-3 py-2 text-sm font-medium text-[var(--mrl-primary)] transition-colors hover:bg-[var(--mrl-primary)]/10"
                            >
                                Ver el material <ArrowRight class="size-4" />
                            </a>
                            <p
                                v-if="l.contenido"
                                class="max-w-prose text-sm whitespace-pre-line"
                            >
                                {{ l.contenido }}
                            </p>

                            <form
                                v-if="l.puede_presentar && l.preguntas.length"
                                class="flex flex-col gap-4"
                                @submit.prevent="presentar(l)"
                            >
                                <fieldset
                                    v-for="p in l.preguntas"
                                    :key="p.indice"
                                    class="flex flex-col gap-1.5"
                                >
                                    <legend class="mb-1 text-sm font-medium">
                                        {{ p.indice + 1 }}. {{ p.pregunta }}
                                    </legend>
                                    <label
                                        v-for="(opcion, i) in p.opciones"
                                        :key="i"
                                        class="flex cursor-pointer items-center gap-2 rounded-lg border border-transparent px-2 py-1.5 text-sm transition-colors hover:border-[var(--mrl-borde)] hover:bg-[var(--mrl-fondo)] has-[:checked]:border-[var(--mrl-primary)]/40 has-[:checked]:bg-[var(--mrl-primary)]/5"
                                    >
                                        <input
                                            v-model="form.respuestas[p.indice]"
                                            type="radio"
                                            :name="`p${l.avance_id}-${p.indice}`"
                                            :value="i"
                                        />
                                        {{ opcion }}
                                    </label>
                                </fieldset>
                                <InputError
                                    :message="
                                        (form.errors as Record<string, string>)
                                            .onboarding
                                    "
                                />
                                <Button
                                    type="submit"
                                    class="w-fit"
                                    :disabled="
                                        form.processing ||
                                        Object.keys(form.respuestas).length <
                                            l.preguntas.length
                                    "
                                    >Enviar respuestas</Button
                                >
                            </form>
                        </div>
                    </article>
                </section>
            </div>

            <aside class="flex flex-col gap-4">
                <section
                    v-if="documentos"
                    class="rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5 transition-shadow hover:shadow-md"
                >
                    <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                        Tus documentos
                    </h2>
                    <p
                        class="mt-1 text-3xl font-semibold text-[var(--mrl-primary)] tabular-nums"
                    >
                        {{ documentos.aprobados }}/{{ documentos.requeridos }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        aprobados · {{ documentos.faltantes }} por subir o
                        corregir
                    </p>
                    <div
                        class="mt-3 h-2 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                    >
                        <div
                            class="h-full rounded-full bg-[var(--mrl-primary)] transition-all"
                            :style="{
                                width: `${documentos.requeridos ? (documentos.aprobados / documentos.requeridos) * 100 : 0}%`,
                            }"
                        />
                    </div>
                </section>

                <section
                    v-if="esperas.length"
                    class="flex flex-col gap-3 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-surface)] p-5"
                >
                    <h2 class="text-sm font-semibold text-[var(--mrl-texto)]">
                        En espera
                    </h2>
                    <div
                        v-for="p in esperas"
                        :key="p.clave"
                        class="flex gap-3 rounded-2xl bg-[var(--mrl-fondo)] p-3 transition-colors hover:bg-[var(--mrl-gold)]/10"
                    >
                        <Clock
                            class="mt-0.5 size-4 shrink-0 text-[var(--mrl-gold)]"
                        />
                        <div>
                            <p
                                class="text-sm font-medium text-[var(--mrl-texto)]"
                            >
                                {{ p.titulo }}
                            </p>
                            <p class="text-xs text-[var(--mrl-texto-suave)]">
                                {{ p.descripcion }}
                            </p>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</template>
