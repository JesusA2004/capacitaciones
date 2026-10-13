<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BriefcaseBusiness,
    FileText,
    GraduationCap,
    Pencil,
    Sparkles,
    UserSearch,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EncabezadoPuesto from '@/components/puestos/EncabezadoPuesto.vue';
import ListaPerfilLectura from '@/components/puestos/ListaPerfilLectura.vue';
import { formatearFecha } from '@/lib/fechas';
import { DESCRIPTIVO, PERFIL_CANDIDATO } from '@/lib/perfilPuesto';
import { dashboard } from '@/routes';
import { edit, index } from '@/routes/rh/perfiles-puesto';
import type { PerfilPuestoDetalle } from '@/types';

const props = defineProps<{
    puesto: PerfilPuestoDetalle['puesto'];
    perfil: PerfilPuestoDetalle['perfil'];
    es_borrador: boolean;
    avance: number;
    actualizado_por: string | null;
    actualizado_en: string | null;
    puedeEditar: boolean;
}>();

defineOptions({
    layout: (pageProps: { puesto: { nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Descriptivos y perfiles', href: index.url() },
            { title: pageProps.puesto.nombre, href: '' },
        ],
    }),
});

type Pestana = 'descriptivo' | 'perfil';
const pestana = ref<Pestana>('descriptivo');

const PESTANAS: { valor: Pestana; etiqueta: string; icono: typeof FileText }[] =
    [
        { valor: 'descriptivo', etiqueta: 'Descriptivo', icono: FileText },
        {
            valor: 'perfil',
            etiqueta: 'Perfil del candidato',
            icono: UserSearch,
        },
    ];

const descriptivoVacio = computed(
    () =>
        !props.perfil.objetivo &&
        DESCRIPTIVO.every((s) => props.perfil[s.clave].length === 0),
);
const perfilVacio = computed(
    () =>
        !props.perfil.escolaridad &&
        !props.perfil.experiencia &&
        PERFIL_CANDIDATO.every((s) => props.perfil[s.clave].length === 0),
);
</script>

<template>
    <Head :title="`Descriptivo · ${puesto.nombre}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <EncabezadoPuesto :puesto="puesto" etiqueta="Descriptivo de puesto">
            <Link
                v-if="puedeEditar && !es_borrador"
                :href="edit.url(puesto.id)"
                class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--mrl-dorado)] px-4 text-sm font-semibold text-[var(--mrl-petroleo)] transition hover:brightness-105"
            >
                <Pencil class="size-4" /> Editar
            </Link>
        </EncabezadoPuesto>

        <!-- Sin descriptivo todavía -->
        <section
            v-if="es_borrador"
            class="flex flex-col items-center gap-3 rounded-3xl border border-dashed border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-6 py-12 text-center"
        >
            <span
                class="flex size-12 items-center justify-center rounded-2xl bg-[var(--mrl-crema)] text-[var(--mrl-dorado-oscuro)]"
            >
                <Sparkles class="size-6" />
            </span>
            <p class="font-semibold">Este puesto aún no tiene descriptivo</p>
            <Link
                v-if="puedeEditar"
                :href="edit.url(puesto.id)"
                class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--mrl-petroleo)] px-4 text-sm font-semibold text-white hover:brightness-110"
            >
                <Pencil class="size-4" /> Crear descriptivo
            </Link>
            <p v-else class="text-sm text-muted-foreground">
                RH lo publicará pronto.
            </p>
        </section>

        <template v-else>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div
                    class="inline-flex max-w-full overflow-x-auto rounded-2xl bg-[var(--mrl-fondo)] p-1"
                    role="tablist"
                    aria-label="Secciones del descriptivo"
                >
                    <button
                        v-for="p in PESTANAS"
                        :key="p.valor"
                        type="button"
                        role="tab"
                        :aria-selected="pestana === p.valor"
                        class="inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl px-4 text-sm font-medium transition-colors"
                        :class="
                            pestana === p.valor
                                ? 'bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-sm'
                                : 'text-muted-foreground hover:text-[var(--mrl-texto)]'
                        "
                        @click="pestana = p.valor"
                    >
                        <component :is="p.icono" class="size-4" />
                        {{ p.etiqueta }}
                    </button>
                </div>
                <p v-if="actualizado_en" class="text-xs text-muted-foreground">
                    Actualizado {{ formatearFecha(actualizado_en) }}
                    <template v-if="actualizado_por">
                        · {{ actualizado_por }}</template
                    >
                </p>
            </div>

            <!-- Descriptivo: el deber ser del puesto -->
            <article
                v-if="pestana === 'descriptivo'"
                class="flex flex-col gap-6 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 sm:p-7"
            >
                <blockquote
                    v-if="perfil.objetivo"
                    class="border-l-4 border-[var(--mrl-dorado)] pl-4 font-serif text-lg leading-relaxed text-[var(--mrl-texto)] sm:text-xl"
                >
                    {{ perfil.objetivo }}
                </blockquote>
                <ListaPerfilLectura
                    v-for="s in DESCRIPTIVO"
                    :key="s.clave"
                    :titulo="s.titulo"
                    :estilo="s.estilo"
                    :items="perfil[s.clave]"
                />
                <p
                    v-if="descriptivoVacio"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    Sin información todavía.
                </p>
            </article>

            <!-- Perfil del candidato -->
            <article
                v-else
                class="flex flex-col gap-6 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 sm:p-7"
            >
                <div
                    v-if="perfil.escolaridad || perfil.experiencia"
                    class="grid gap-3 sm:grid-cols-2"
                >
                    <div
                        v-if="perfil.escolaridad"
                        class="flex items-start gap-3 rounded-2xl bg-[var(--mrl-crema)] p-4"
                    >
                        <GraduationCap
                            class="mt-0.5 size-5 shrink-0 text-[var(--mrl-dorado-oscuro)]"
                        />
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Escolaridad
                            </p>
                            <p class="font-medium">{{ perfil.escolaridad }}</p>
                        </div>
                    </div>
                    <div
                        v-if="perfil.experiencia"
                        class="flex items-start gap-3 rounded-2xl bg-[var(--mrl-crema)] p-4"
                    >
                        <BriefcaseBusiness
                            class="mt-0.5 size-5 shrink-0 text-[var(--mrl-dorado-oscuro)]"
                        />
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Experiencia
                            </p>
                            <p class="font-medium whitespace-pre-line">
                                {{ perfil.experiencia }}
                            </p>
                        </div>
                    </div>
                </div>
                <ListaPerfilLectura
                    v-for="s in PERFIL_CANDIDATO"
                    :key="s.clave"
                    :titulo="s.titulo"
                    :estilo="s.estilo"
                    :items="perfil[s.clave]"
                />
                <p
                    v-if="perfilVacio"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    Sin información todavía.
                </p>
            </article>
        </template>
    </div>
</template>
