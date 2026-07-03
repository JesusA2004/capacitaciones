<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { BookOpen, CheckCircle2 } from '@lucide/vue';
import ProximasSesionesCard from '@/components/Dashboard/ProximasSesionesCard.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import { index as indexMiCapacitacion } from '@/routes/mi-capacitacion';
import type { SesionProximaItem } from '@/types';

defineProps<{
    cursosEnProgreso: number;
    cursosCompletados: number;
    proximasSesiones: SesionProximaItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="Inicio" description="Tu resumen de capacitación" />

        <div class="grid gap-4 sm:grid-cols-2">
            <a
                :href="indexMiCapacitacion.url()"
                class="flex items-center gap-3 rounded-xl border p-4 transition-colors hover:bg-accent"
            >
                <BookOpen class="size-8 text-[var(--brand-primary)]" />
                <div>
                    <p class="text-2xl font-semibold">{{ cursosEnProgreso }}</p>
                    <p class="text-xs text-muted-foreground">
                        Curso(s) en progreso
                    </p>
                </div>
            </a>
            <div class="flex items-center gap-3 rounded-xl border p-4">
                <CheckCircle2 class="size-8 text-[var(--success)]" />
                <div>
                    <p class="text-2xl font-semibold">
                        {{ cursosCompletados }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Curso(s) completado(s)
                    </p>
                </div>
            </div>
        </div>

        <ProximasSesionesCard :sesiones="proximasSesiones" />
    </div>
</template>
