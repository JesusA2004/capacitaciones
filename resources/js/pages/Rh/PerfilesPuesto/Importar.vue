<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CircleCheck, FileText, TriangleAlert } from '@lucide/vue';
import { reactive } from 'vue';
import { Spinner } from '@/components/ui/spinner';
import { dashboard } from '@/routes';
import { importar, index } from '@/routes/rh/perfiles-puesto';

type Analisis = {
    archivo: string;
    sha256: string;
    paginas: number;
    estado: 'listo' | 'confirmar_puesto' | 'ya_importado' | 'requiere_revision';
    mensaje?: string;
    detectado: Record<string, number | boolean>;
    sugeridos: { id: number; nombre: string; por: 'exacto' | 'alias' }[];
    importado: { id: number; version: number; puestos: string[] } | null;
};

const props = defineProps<{
    archivos: Analisis[];
    puestos: { id: number; nombre: string }[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Descriptivos y perfiles', href: index.url() },
            { title: 'Importar', href: '' },
        ],
    },
});

const ETIQUETAS: Record<string, string> = {
    area: 'Área',
    jefe_inmediato: 'Jefe inmediato',
    formacion: 'Formación',
    experiencia: 'Experiencia',
    objetivo: 'Objetivo',
    funciones: 'funciones',
    conocimientos: 'conocimientos',
    competencias: 'competencias',
    actividades_criticas: 'actividades críticas',
    facultades: 'facultades',
    plan_formacion: 'plan de formación',
    compromisos_culturales: 'compromisos culturales',
};

function detectado(a: Analisis): string[] {
    return Object.entries(a.detectado)
        .filter(([, v]) => v)
        .map(([k, v]) =>
            typeof v === 'number' ? `${v} ${ETIQUETAS[k]}` : ETIQUETAS[k],
        );
}

// Puestos elegidos por archivo (preseleccionados los sugeridos).
const elegidos = reactive<Record<string, number[]>>(
    Object.fromEntries(
        props.archivos.map((a) => [a.sha256, a.sugeridos.map((s) => s.id)]),
    ),
);

const form = useForm({ sha256: '', puestos: [] as number[] });

function alternar(sha: string, id: number) {
    const actual = elegidos[sha] ?? [];
    elegidos[sha] = actual.includes(id)
        ? actual.filter((x) => x !== id)
        : [...actual, id];
}

function importarArchivo(a: Analisis) {
    form.sha256 = a.sha256;
    form.puestos = elegidos[a.sha256] ?? [];
    form.post(importar.url(), { preserveScroll: true });
}
</script>

<template>
    <Head title="Importar descriptivos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <header>
            <h1 class="font-serif text-3xl text-[var(--mrl-petroleo)]">
                Importar descriptivos oficiales
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                Se leen de la carpeta de descriptivos. El PDF se guarda tal
                cual.
            </p>
        </header>

        <article
            v-for="a in archivos"
            :key="a.sha256"
            class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 sm:p-5"
        >
            <div class="flex flex-wrap items-start gap-3">
                <span
                    class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[var(--mrl-crema)] text-[var(--mrl-dorado-oscuro)]"
                >
                    <FileText class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="font-semibold break-words">{{ a.archivo }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ a.paginas }} página(s)
                    </p>
                </div>
                <span
                    class="rounded-full px-2.5 py-1 text-xs font-medium"
                    :class="{
                        'bg-success-soft text-[var(--mrl-verde)]':
                            a.estado === 'ya_importado',
                        'bg-[var(--mrl-fondo)] text-[var(--mrl-petroleo)]':
                            a.estado === 'listo',
                        'bg-warning/15 text-warning':
                            a.estado === 'confirmar_puesto' ||
                            a.estado === 'requiere_revision',
                    }"
                >
                    {{
                        a.estado === 'ya_importado'
                            ? `Importado · v${a.importado?.version}`
                            : a.estado === 'listo'
                              ? 'Listo para importar'
                              : a.estado === 'requiere_revision'
                                ? 'Revisión manual'
                                : 'Confirmar puesto'
                    }}
                </span>
            </div>

            <p v-if="a.mensaje" class="text-sm text-warning">{{ a.mensaje }}</p>

            <ul v-else class="flex flex-wrap gap-x-4 gap-y-1 text-sm">
                <li
                    v-for="d in detectado(a)"
                    :key="d"
                    class="inline-flex items-center gap-1.5"
                >
                    <CircleCheck class="size-4 text-[var(--mrl-verde)]" />
                    {{ d }}
                </li>
            </ul>

            <div
                v-if="a.estado !== 'requiere_revision'"
                class="flex flex-col gap-2"
            >
                <p v-if="a.estado === 'ya_importado'" class="text-sm">
                    Asociado a
                    <strong>{{ a.importado?.puestos.join(', ') }}</strong>
                </p>
                <template v-else>
                    <p
                        v-if="a.sugeridos.length === 0"
                        class="flex items-center gap-2 text-sm font-medium text-warning"
                    >
                        <TriangleAlert class="size-4" /> Necesitamos confirmar
                        el puesto
                    </p>
                    <p v-else class="text-sm">
                        Asociado a:
                        <span
                            v-for="s in a.sugeridos"
                            :key="s.id"
                            class="font-semibold"
                        >
                            {{ s.nombre
                            }}<span class="font-normal text-muted-foreground">
                                ({{ s.por }})</span
                            >;
                        </span>
                    </p>
                    <details class="text-sm">
                        <summary
                            class="cursor-pointer text-[var(--mrl-petroleo)]"
                        >
                            {{
                                a.sugeridos.length
                                    ? 'Revisar puestos'
                                    : 'Seleccionar puesto'
                            }}
                        </summary>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <button
                                v-for="p in puestos"
                                :key="p.id"
                                type="button"
                                class="rounded-full border px-3 py-1 text-xs transition-colors"
                                :class="
                                    (elegidos[a.sha256] ?? []).includes(p.id)
                                        ? 'border-[var(--mrl-petroleo)] bg-[var(--mrl-petroleo)] text-white'
                                        : 'border-[var(--mrl-borde)] hover:bg-[var(--mrl-fondo)]'
                                "
                                @click="alternar(a.sha256, p.id)"
                            >
                                {{ p.nombre }}
                            </button>
                        </div>
                    </details>
                    <div class="flex justify-end">
                        <button
                            type="button"
                            class="inline-flex min-h-10 items-center gap-2 rounded-xl bg-[var(--mrl-petroleo)] px-4 text-sm font-semibold text-white hover:brightness-110 disabled:opacity-50"
                            :disabled="
                                form.processing ||
                                (elegidos[a.sha256] ?? []).length === 0
                            "
                            @click="importarArchivo(a)"
                        >
                            <Spinner
                                v-if="
                                    form.processing && form.sha256 === a.sha256
                                "
                            />
                            Importar
                        </button>
                    </div>
                    <p
                        v-if="form.errors.puestos && form.sha256 === a.sha256"
                        class="text-xs text-danger"
                    >
                        {{ form.errors.puestos }}
                    </p>
                </template>
            </div>
        </article>

        <p
            v-if="archivos.length === 0"
            class="py-10 text-center text-sm text-muted-foreground"
        >
            No hay PDF en la carpeta de descriptivos.
        </p>
    </div>
</template>
