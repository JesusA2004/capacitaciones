<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { FileText, Sparkles, UserSearch } from '@lucide/vue';
import { computed, ref } from 'vue';
import EditorListaPerfil from '@/components/puestos/EditorListaPerfil.vue';
import EncabezadoPuesto from '@/components/puestos/EncabezadoPuesto.vue';
import { Spinner } from '@/components/ui/spinner';
import { DESCRIPTIVO, PERFIL_CANDIDATO } from '@/lib/perfilPuesto';
import { dashboard } from '@/routes';
import { index, show, update } from '@/routes/rh/perfiles-puesto';
import type { PerfilPuestoDetalle } from '@/types';

const props = defineProps<{
    puesto: PerfilPuestoDetalle['puesto'];
    perfil: PerfilPuestoDetalle['perfil'];
    es_borrador: boolean;
}>();

defineOptions({
    layout: (pageProps: { puesto: { id: number; nombre: string } }) => ({
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Descriptivos y perfiles', href: index.url() },
            {
                title: pageProps.puesto.nombre,
                href: show.url(pageProps.puesto.id),
            },
            { title: 'Editar', href: '' },
        ],
    }),
});

const form = useForm({
    objetivo: props.perfil.objetivo ?? '',
    escolaridad: props.perfil.escolaridad ?? '',
    experiencia: props.perfil.experiencia ?? '',
    funciones: [...props.perfil.funciones],
    responsabilidades: [...props.perfil.responsabilidades],
    indicadores: [...props.perfil.indicadores],
    conocimientos: [...props.perfil.conocimientos],
    habilidades: [...props.perfil.habilidades],
    competencias: [...props.perfil.competencias],
    valores: [...props.perfil.valores],
    requisitos: [...props.perfil.requisitos],
});

type Pestana = 'descriptivo' | 'perfil';
const pestana = ref<Pestana>('descriptivo');

function error(clave: string): string | undefined {
    const errores = form.errors as Record<string, string | undefined>;

    return (
        errores[clave] ??
        Object.entries(errores).find(([k]) => k.startsWith(`${clave}.`))?.[1]
    );
}

const conErrores = computed(() => {
    const claves = Object.keys(form.errors);
    const de = (lista: string[]) =>
        claves.some((k) => lista.some((c) => k === c || k.startsWith(`${c}.`)));

    return {
        descriptivo: de(['objetivo', ...DESCRIPTIVO.map((s) => s.clave)]),
        perfil: de([
            'escolaridad',
            'experiencia',
            ...PERFIL_CANDIDATO.map((s) => s.clave),
        ]),
    };
});

const PESTANAS: { valor: Pestana; etiqueta: string; icono: typeof FileText }[] =
    [
        { valor: 'descriptivo', etiqueta: 'Descriptivo', icono: FileText },
        {
            valor: 'perfil',
            etiqueta: 'Perfil del candidato',
            icono: UserSearch,
        },
    ];

function guardar() {
    form.put(update.url(props.puesto.id), { preserveScroll: true });
}
</script>

<template>
    <Head :title="`Editar · ${puesto.nombre}`" />

    <form
        class="pagina-ancha flex flex-col gap-5 pb-24 md:pb-0"
        @submit.prevent="guardar"
    >
        <EncabezadoPuesto :puesto="puesto" etiqueta="Editando descriptivo" />

        <p
            v-if="es_borrador"
            class="flex items-center gap-2 rounded-2xl bg-[var(--mrl-crema)] px-4 py-3 text-sm"
        >
            <Sparkles class="size-4 shrink-0 text-[var(--mrl-dorado-oscuro)]" />
            Borrador sugerido con lo que ya conocemos del puesto. Revísalo y
            guarda.
        </p>

        <div
            class="inline-flex max-w-full self-start overflow-x-auto rounded-2xl bg-[var(--mrl-fondo)] p-1"
            role="tablist"
            aria-label="Secciones"
        >
            <button
                v-for="p in PESTANAS"
                :key="p.valor"
                type="button"
                role="tab"
                :aria-selected="pestana === p.valor"
                class="relative inline-flex min-h-10 shrink-0 items-center gap-2 rounded-xl px-4 text-sm font-medium transition-colors"
                :class="
                    pestana === p.valor
                        ? 'bg-[var(--mrl-superficie)] text-[var(--mrl-texto)] shadow-sm'
                        : 'text-muted-foreground hover:text-[var(--mrl-texto)]'
                "
                @click="pestana = p.valor"
            >
                <component :is="p.icono" class="size-4" />
                {{ p.etiqueta }}
                <span
                    v-if="conErrores[p.valor]"
                    class="size-2 rounded-full bg-danger"
                    aria-label="Con errores"
                />
            </button>
        </div>

        <section
            v-show="pestana === 'descriptivo'"
            class="grid gap-6 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 sm:p-7"
        >
            <label class="flex flex-col gap-2">
                <span class="text-sm font-medium">Objetivo del puesto</span>
                <textarea
                    v-model="form.objetivo"
                    rows="3"
                    maxlength="2000"
                    class="rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 py-2.5 font-serif text-base leading-relaxed outline-none focus:border-[var(--mrl-petroleo)]/50 focus:ring-2 focus:ring-[var(--mrl-petroleo)]/15"
                    placeholder="Para qué existe el puesto"
                />
                <span v-if="error('objetivo')" class="text-xs text-danger">{{
                    error('objetivo')
                }}</span>
            </label>
            <EditorListaPerfil
                v-for="s in DESCRIPTIVO"
                :key="s.clave"
                v-model="form[s.clave]"
                :titulo="s.titulo"
                :estilo="s.estilo"
                :ejemplo="s.ejemplo"
                :error="error(s.clave)"
            />
        </section>

        <section
            v-show="pestana === 'perfil'"
            class="grid gap-6 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 sm:p-7"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="flex flex-col gap-2">
                    <span class="text-sm font-medium">Escolaridad</span>
                    <input
                        v-model="form.escolaridad"
                        type="text"
                        maxlength="255"
                        class="min-h-11 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 text-sm outline-none focus:border-[var(--mrl-petroleo)]/50 focus:ring-2 focus:ring-[var(--mrl-petroleo)]/15"
                        placeholder="Ej. Bachillerato terminado"
                    />
                    <span
                        v-if="error('escolaridad')"
                        class="text-xs text-danger"
                        >{{ error('escolaridad') }}</span
                    >
                </label>
                <label class="flex flex-col gap-2">
                    <span class="text-sm font-medium">Experiencia</span>
                    <input
                        v-model="form.experiencia"
                        type="text"
                        maxlength="2000"
                        class="min-h-11 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-3 text-sm outline-none focus:border-[var(--mrl-petroleo)]/50 focus:ring-2 focus:ring-[var(--mrl-petroleo)]/15"
                        placeholder="Ej. 1 año en cobranza"
                    />
                    <span
                        v-if="error('experiencia')"
                        class="text-xs text-danger"
                        >{{ error('experiencia') }}</span
                    >
                </label>
            </div>
            <EditorListaPerfil
                v-for="s in PERFIL_CANDIDATO"
                :key="s.clave"
                v-model="form[s.clave]"
                :titulo="s.titulo"
                :estilo="s.estilo"
                :ejemplo="s.ejemplo"
                :error="error(s.clave)"
            />
        </section>

        <!-- Acciones siempre visibles -->
        <div
            class="fixed inset-x-3 bottom-[5.25rem] z-40 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]/95 px-4 py-3 shadow-lg backdrop-blur md:sticky md:inset-x-auto md:bottom-3 md:z-20"
        >
            <div class="flex items-center justify-end gap-2 pr-14 md:pr-0">
                <span
                    v-if="form.isDirty"
                    class="mr-auto text-xs text-muted-foreground"
                >
                    Cambios sin guardar
                </span>
                <Link
                    :href="show.url(puesto.id)"
                    class="inline-flex min-h-10 items-center rounded-xl px-4 text-sm font-medium text-muted-foreground hover:bg-[var(--mrl-fondo)] hover:text-[var(--mrl-texto)]"
                >
                    Cancelar
                </Link>
                <button
                    type="submit"
                    :disabled="form.processing"
                    class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--mrl-petroleo)] px-5 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-60"
                >
                    <Spinner v-if="form.processing" />
                    Guardar
                </button>
            </div>
        </div>
    </form>
</template>
