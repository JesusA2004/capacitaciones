<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    BadgeCheck,
    BriefcaseBusiness,
    Download,
    FileText,
    GraduationCap,
    Pencil,
    Sparkles,
    TriangleAlert,
    UserSearch,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import VisorPdf from '@/components/documentos/VisorPdf.vue';
import DescriptivoOficialSecciones from '@/components/puestos/DescriptivoOficialSecciones.vue';
import EncabezadoPuesto from '@/components/puestos/EncabezadoPuesto.vue';
import ListaPerfilLectura from '@/components/puestos/ListaPerfilLectura.vue';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatearFecha } from '@/lib/fechas';
import { DESCRIPTIVO, PERFIL_CANDIDATO } from '@/lib/perfilPuesto';
import { dashboard } from '@/routes';
import { edit, index, pdf } from '@/routes/rh/perfiles-puesto';
import type { PerfilPuestoDetalle } from '@/types';

const props = defineProps<{
    puesto: PerfilPuestoDetalle['puesto'];
    perfil: PerfilPuestoDetalle['perfil'];
    es_borrador: boolean;
    avance: number;
    actualizado_por: string | null;
    actualizado_en: string | null;
    puedeEditar: boolean;
    origen: PerfilPuestoDetalle['origen'];
    descriptivo: PerfilPuestoDetalle['descriptivo'];
}>();

const visorAbierto = ref(false);
const urlPdf = computed(() =>
    props.descriptivo
        ? pdf.url({
              puesto: props.puesto.id,
              descriptivo: props.descriptivo.id,
          })
        : '',
);

/** Datos del documento que se muestran como ficha (sin inferir nada). */
const ficha = computed(() => {
    const d = props.descriptivo?.datos;

    if (!d) {
        return [];
    }

    return [
        { etiqueta: 'Área', valor: d.area },
        { etiqueta: 'Jefe inmediato (documento)', valor: d.jefe_inmediato },
        { etiqueta: 'Personal a cargo', valor: d.personal_a_cargo },
        { etiqueta: 'Tipo de empleado', valor: d.tipo_empleado },
    ].filter((f) => f.valor);
});

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
            <div class="flex flex-wrap gap-2">
                <template v-if="descriptivo">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-white/10 px-4 text-sm font-medium text-white hover:bg-white/20"
                        @click="visorAbierto = true"
                    >
                        <FileText class="size-4" /> Ver PDF original
                    </button>
                    <a
                        :href="`${urlPdf}?descargar=1`"
                        class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-white/10 px-4 text-sm font-medium text-white hover:bg-white/20"
                    >
                        <Download class="size-4" /> Descargar PDF
                    </a>
                </template>
                <Link
                    v-if="puedeEditar && !es_borrador"
                    :href="edit.url(puesto.id)"
                    class="inline-flex min-h-10 items-center justify-center gap-2 rounded-xl bg-[var(--mrl-dorado)] px-4 text-sm font-semibold text-[var(--mrl-petroleo)] transition hover:brightness-105"
                >
                    <Pencil class="size-4" /> Editar información
                </Link>
            </div>
        </EncabezadoPuesto>

        <!-- Documento oficial vs. información presentada por PEOPLE -->
        <div
            v-if="descriptivo"
            class="flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-muted-foreground"
        >
            <span class="inline-flex items-center gap-1.5">
                <BadgeCheck class="size-4 text-[var(--mrl-verde)]" />
                Documento oficial · Versión {{ descriptivo.version }}
                <template v-if="descriptivo.fecha">
                    · {{ descriptivo.fecha.toLowerCase() }}</template
                >
            </span>
            <span
                v-if="origen === 'rh'"
                class="rounded-full bg-[var(--mrl-crema)] px-2.5 py-0.5 text-[var(--mrl-dorado-oscuro)]"
                >Información ajustada por RH</span
            >
            <span
                v-else-if="origen === 'documento'"
                class="rounded-full bg-[var(--mrl-fondo)] px-2.5 py-0.5"
                >Información tomada del documento oficial</span
            >
        </div>
        <ul
            v-if="puedeEditar && descriptivo?.advertencias.length"
            class="flex flex-col gap-1 rounded-2xl border border-warning/30 bg-warning/5 px-4 py-3 text-sm"
        >
            <li
                v-for="a in descriptivo.advertencias"
                :key="a"
                class="flex gap-2"
            >
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" />{{
                    a
                }}
            </li>
        </ul>

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
                <dl
                    v-if="ficha.length"
                    class="grid gap-3 rounded-2xl bg-[var(--mrl-fondo)]/70 p-4 text-sm sm:grid-cols-2 xl:grid-cols-4"
                >
                    <div v-for="f in ficha" :key="f.etiqueta">
                        <dt class="text-xs text-muted-foreground">
                            {{ f.etiqueta }}
                        </dt>
                        <dd class="font-medium">{{ f.valor }}</dd>
                    </div>
                </dl>
                <DescriptivoOficialSecciones
                    v-if="descriptivo"
                    :datos="descriptivo.datos"
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
                <dl
                    v-if="descriptivo"
                    class="grid gap-3 text-sm sm:grid-cols-2"
                >
                    <div v-if="descriptivo.datos.formacion.deseable">
                        <dt class="text-xs text-muted-foreground">
                            Formación deseable
                        </dt>
                        <dd>{{ descriptivo.datos.formacion.deseable }}</dd>
                    </div>
                    <div
                        v-if="descriptivo.datos.edad || descriptivo.datos.sexo"
                    >
                        <dt class="text-xs text-muted-foreground">
                            Edad y sexo (dato del documento)
                        </dt>
                        <dd>
                            {{
                                [descriptivo.datos.edad, descriptivo.datos.sexo]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </dd>
                        <dd class="text-[11px] text-muted-foreground">
                            Informativo: no se usa para filtrar ni calificar
                            candidatos.
                        </dd>
                    </div>
                </dl>
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

    <Dialog v-model:open="visorAbierto">
        <DialogContent
            class="flex h-[92vh] max-w-5xl flex-col gap-3 p-3 sm:p-4"
        >
            <DialogHeader>
                <DialogTitle class="truncate pr-8 text-sm">
                    {{ descriptivo?.archivo }}
                </DialogTitle>
            </DialogHeader>
            <div class="min-h-0 flex-1 overflow-auto">
                <VisorPdf
                    v-if="visorAbierto && descriptivo"
                    :url="urlPdf"
                    :titulo="`Descriptivo · ${puesto.nombre}`"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
