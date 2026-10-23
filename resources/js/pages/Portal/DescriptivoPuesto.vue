<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, BriefcaseBusiness } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
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
            class="rounded-3xl border border-border/60 bg-card shadow-sm"
        >
            <EmptyState
                :icono="BriefcaseBusiness"
                titulo="Tu descriptivo todavía no está listo"
                descripcion="Recursos Humanos todavía no te envía tu descriptivo de puesto. Te avisaremos cuando esté listo."
            >
                <Link
                    :href="miProceso()"
                    class="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 text-sm font-medium text-primary hover:bg-salvia/60"
                >
                    Ir a Mis pendientes <ArrowRight class="size-4" />
                </Link>
            </EmptyState>
        </section>
    </div>
</template>
