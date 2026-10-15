<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, ArrowRight, BookOpen, Clock } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useGuia } from '@/composables/useGuia';
import {
    articulo,
    NOMBRE_ROL,
    proceso as buscarProceso,
    PROCESOS,
    articuloVisible,
    procesoVisible,
} from '@/lib/guia';
import { iconoGuia } from '@/lib/guia/iconos';
import type { ArticuloGuia } from '@/lib/guia/tipos';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ayuda', href: '/ayuda' }],
    },
});

const props = defineProps<{ proceso: string }>();

const { accesos, alcance } = useGuia();

// Solo procesos y artículos que le corresponden a quien lee (rol + modo).
const proc = computed(() => {
    const encontrado = buscarProceso(props.proceso);

    return encontrado && procesoVisible(encontrado, alcance.value)
        ? encontrado
        : undefined;
});
const articulos = computed(() =>
    (proc.value?.articulos ?? [])
        .map((id) => articulo(id))
        .filter(
            (a): a is ArticuloGuia =>
                a !== undefined && articuloVisible(a, alcance.value),
        ),
);
const visibles = computed(() =>
    PROCESOS.filter((p) => procesoVisible(p, alcance.value)),
);
const anterior = computed(() => {
    const i = visibles.value.findIndex((p) => p.id === proc.value?.id);

    return i > 0 ? visibles.value[i - 1] : undefined;
});
const siguiente = computed(() => {
    const i = visibles.value.findIndex((p) => p.id === proc.value?.id);

    return i >= 0 ? visibles.value[i + 1] : undefined;
});
</script>

<template>
    <Head :title="proc ? `Guía · ${proc.titulo}` : 'Guía'" />

    <div class="pagina-media flex flex-col gap-8">
        <template v-if="proc">
            <nav
                class="flex items-center gap-1.5 text-xs text-[var(--mrl-texto-suave)]"
                aria-label="Ubicación en la guía"
            >
                <Link href="/ayuda" class="hover:underline">Guía</Link>
                <span>›</span>
                <span class="text-[var(--mrl-texto)]"
                    >{{ proc.numero }}. {{ proc.titulo }}</span
                >
            </nav>

            <header
                class="flex flex-col gap-5 rounded-[2rem] border border-[var(--mrl-borde)] bg-gradient-to-br from-crema via-[var(--mrl-superficie)] to-salvia/60 p-6 sm:flex-row sm:items-center sm:p-8"
            >
                <span
                    class="flex size-16 shrink-0 items-center justify-center rounded-3xl bg-[var(--mrl-superficie)] text-[var(--mrl-petroleo)] shadow-sm"
                >
                    <component :is="iconoGuia(proc.icono)" class="size-8" />
                </span>
                <div class="min-w-0 space-y-2">
                    <p
                        class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
                    >
                        Proceso {{ proc.numero }} de {{ PROCESOS.length }}
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight">
                        {{ proc.titulo }}
                    </h1>
                    <p
                        class="max-w-3xl text-pretty text-[var(--mrl-texto-suave)]"
                    >
                        {{ proc.resumen }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2 pt-1 text-xs">
                        <span
                            class="flex items-center gap-1 text-[var(--mrl-texto-suave)]"
                            ><Clock class="size-3.5" /> {{ proc.tiempo }}</span
                        >
                        <Badge
                            v-for="r in proc.roles"
                            :key="r"
                            variant="secondary"
                            class="bg-salvia text-[var(--mrl-texto)]"
                            >{{ NOMBRE_ROL[r] }}</Badge
                        >
                    </div>
                </div>
            </header>

            <ol class="grid gap-4">
                <li
                    v-for="(a, i) in articulos"
                    :key="a.id"
                    class="grid gap-4 rounded-3xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5 sm:grid-cols-[auto_1fr_auto] sm:items-center"
                >
                    <span
                        class="flex size-10 items-center justify-center rounded-full bg-[var(--mrl-petroleo)] text-sm font-semibold text-white tabular-nums"
                    >
                        {{ i + 1 }}
                    </span>
                    <div class="min-w-0">
                        <h2 class="text-lg font-semibold">{{ a.titulo }}</h2>
                        <p
                            class="text-sm text-pretty text-[var(--mrl-texto-suave)]"
                        >
                            {{ a.resumen }}
                        </p>
                        <p class="mt-2 flex flex-wrap gap-1.5">
                            <Badge
                                v-for="r in a.quien"
                                :key="r"
                                variant="outline"
                                class="text-[11px]"
                                >{{ NOMBRE_ROL[r] }}</Badge
                            >
                        </p>
                    </div>
                    <div
                        class="flex flex-wrap gap-2 sm:flex-col sm:items-stretch"
                    >
                        <Button as-child size="sm" class="gap-1.5">
                            <Link :href="`/ayuda/articulo/${a.id}`"
                                ><BookOpen class="size-3.5" /> Ver guía</Link
                            >
                        </Button>
                        <Button
                            v-for="acc in accesos(a.accesos).slice(0, 1)"
                            :key="acc.ruta"
                            as-child
                            size="sm"
                            variant="outline"
                        >
                            <Link :href="acc.ruta">{{ acc.etiqueta }}</Link>
                        </Button>
                    </div>
                </li>
            </ol>

            <nav
                class="grid gap-3 border-t border-[var(--mrl-borde)] pt-6 sm:grid-cols-2"
                aria-label="Otros procesos"
            >
                <Link
                    v-if="anterior"
                    :href="`/ayuda/proceso/${anterior.id}`"
                    class="tarjeta-interactiva rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <span
                        class="flex items-center gap-1 text-xs text-[var(--mrl-texto-suave)]"
                        ><ArrowLeft class="size-3.5" /> Proceso anterior</span
                    >
                    <span class="font-medium">{{ anterior.titulo }}</span>
                </Link>
                <span v-else />
                <Link
                    v-if="siguiente"
                    :href="`/ayuda/proceso/${siguiente.id}`"
                    class="tarjeta-interactiva rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-right"
                >
                    <span
                        class="flex items-center justify-end gap-1 text-xs text-[var(--mrl-texto-suave)]"
                        >Siguiente proceso <ArrowRight class="size-3.5"
                    /></span>
                    <span class="font-medium">{{ siguiente.titulo }}</span>
                </Link>
            </nav>
        </template>

        <div v-else class="py-20 text-center">
            <p class="mb-4">Este proceso no corresponde a tu rol.</p>
            <Button as-child variant="outline"
                ><Link href="/ayuda">Volver a la guía</Link></Button
            >
        </div>
    </div>
</template>
