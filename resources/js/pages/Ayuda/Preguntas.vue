<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, CircleHelp } from '@lucide/vue';
import GuiaBuscador from '@/components/guia/GuiaBuscador.vue';
import { PREGUNTAS_FRECUENTES } from '@/lib/guia';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Ayuda', href: '/ayuda' },
            { title: 'Preguntas frecuentes', href: '/ayuda/preguntas' },
        ],
    },
});
</script>

<template>
    <Head title="Preguntas frecuentes" />

    <div class="pagina-media flex flex-col gap-6">
        <header class="space-y-2">
            <p
                class="text-xs font-semibold tracking-[0.18em] text-bronce uppercase"
            >
                Guía del sistema
            </p>
            <h1 class="text-3xl font-semibold tracking-tight">
                Preguntas frecuentes
            </h1>
        </header>

        <div class="max-w-2xl"><GuiaBuscador /></div>

        <div class="grid gap-3">
            <details
                v-for="p in PREGUNTAS_FRECUENTES"
                :key="p.pregunta"
                class="group rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 open:bg-crema/30"
            >
                <summary
                    class="flex cursor-pointer list-none items-center gap-3 font-medium"
                >
                    <CircleHelp class="size-5 shrink-0 text-oro" />
                    {{ p.pregunta }}
                </summary>
                <p
                    class="mt-3 pl-8 text-sm text-pretty text-[var(--mrl-texto-suave)]"
                >
                    {{ p.respuesta }}
                </p>
                <Link
                    v-if="p.articulo"
                    :href="`/ayuda/articulo/${p.articulo}`"
                    class="mt-2 ml-8 inline-flex items-center gap-1 text-xs font-semibold text-[var(--mrl-petroleo)] hover:underline"
                >
                    Leer la guía completa <ArrowRight class="size-3" />
                </Link>
            </details>
        </div>
    </div>
</template>
