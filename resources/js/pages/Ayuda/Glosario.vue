<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Input } from '@/components/ui/input';
import { GLOSARIO } from '@/lib/guia';
import { normalizar } from '@/lib/guia/buscador';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Ayuda', href: '/ayuda' },
            { title: 'Glosario', href: '/ayuda/glosario' },
        ],
    },
});

const filtro = ref('');
const terminos = computed(() => {
    const f = normalizar(filtro.value);

    return [...GLOSARIO]
        .sort((a, b) => a.termino.localeCompare(b.termino, 'es'))
        .filter(
            (t) =>
                f === '' ||
                normalizar(`${t.termino} ${t.definicion}`).includes(f),
        );
});
</script>

<template>
    <Head title="Glosario" />

    <div class="pagina-media flex flex-col gap-6">
        <header class="space-y-2">
            <p
                class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
            >
                Guía del sistema
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">Glosario</h1>
            <p class="text-[var(--mrl-texto-suave)]">
                Las palabras de PEOPLE explicadas sin tecnicismos.
            </p>
        </header>

        <div class="relative max-w-md">
            <Search
                class="pointer-events-none absolute top-2.5 left-3 size-4 text-[var(--mrl-texto-suave)]"
            />
            <Input
                v-model="filtro"
                type="search"
                placeholder="Buscar un término…"
                aria-label="Buscar en el glosario"
                class="pl-9"
            />
        </div>

        <dl class="grid gap-3 sm:grid-cols-2">
            <div
                v-for="t in terminos"
                :key="t.termino"
                class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <dt class="font-semibold">{{ t.termino }}</dt>
                <dd class="text-sm text-pretty text-[var(--mrl-texto-suave)]">
                    {{ t.definicion }}
                </dd>
                <Link
                    v-if="t.articulo"
                    :href="`/ayuda/articulo/${t.articulo}`"
                    class="mt-auto inline-flex items-center gap-1 text-xs font-semibold text-[var(--mrl-petroleo)] hover:underline"
                >
                    Ver en la guía <ArrowRight class="size-3" />
                </Link>
            </div>
        </dl>
        <p
            v-if="terminos.length === 0"
            class="text-sm text-[var(--mrl-texto-suave)]"
        >
            Ningún término coincide con «{{ filtro }}».
        </p>
    </div>
</template>
