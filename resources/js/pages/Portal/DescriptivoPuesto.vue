<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, BriefcaseBusiness } from '@lucide/vue';
import DescriptivoPuestoColaborador from '@/components/onboarding/DescriptivoPuestoColaborador.vue';
import { dashboard } from '@/routes';
import { miProceso } from '@/routes/portal';
import type { DescriptivoPuestoColaborador as Acuse } from '@/types';

/**
 * «Mi descriptivo de puesto» (Mi espacio): el que RH me envió, siempre el
 * propio. Abrirlo lo marca como leído; para confirmarlo se pide la casilla
 * «Enterado(a)…» y «Estoy de acuerdo con…» (Portal\DescriptivoPuestoController).
 */
defineProps<{ acuse: Acuse | null }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Mis pendientes', href: miProceso() },
            { title: 'Mi descriptivo de puesto', href: '' },
        ],
    },
});
</script>

<template>
    <Head title="Mi descriptivo de puesto" />

    <div class="pagina-ancha flex flex-col gap-4 pb-10">
        <header>
            <h1 class="text-2xl font-semibold tracking-tight">
                Mi descriptivo de puesto
            </h1>
            <p class="text-sm text-muted-foreground">
                Tus actividades y responsabilidades, tal como las define Mr.
                Lana para tu puesto.
            </p>
        </header>

        <DescriptivoPuestoColaborador v-if="acuse" :acuse="acuse" />

        <section
            v-else
            class="flex flex-col items-start gap-3 rounded-3xl border border-border/60 bg-card p-6"
        >
            <BriefcaseBusiness class="size-8 text-muted-foreground" />
            <p class="text-sm text-muted-foreground">
                Recursos Humanos todavía no te envía tu descriptivo de puesto.
                Te avisaremos cuando esté listo.
            </p>
            <Link
                :href="miProceso()"
                class="inline-flex items-center gap-2 text-sm font-medium text-primary hover:underline"
            >
                Ir a Mis pendientes <ArrowRight class="size-4" />
            </Link>
        </section>
    </div>
</template>
