<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Briefcase, UserRound, Users2 } from '@lucide/vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { dashboard } from '@/routes';
import { reclutamiento as index } from '@/routes/rh';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { index as indexVacantes } from '@/routes/rh/vacantes';

defineProps<{
    resumen: {
        vacantes_abiertas: number;
        vacantes_cubiertas: number;
        candidatos_en_proceso: number;
        candidatos_contratados: number;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reclutamiento', href: index.url() },
        ],
    },
});
</script>

<template>
    <Head title="Reclutamiento" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Reclutamiento"
            descripcion="Vista general de vacantes y candidatos en proceso."
            :icono="Users2"
        />

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">Vacantes abiertas</p>
                <p class="text-2xl font-semibold">
                    {{ resumen.vacantes_abiertas }}
                </p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">Vacantes cubiertas</p>
                <p class="text-2xl font-semibold">
                    {{ resumen.vacantes_cubiertas }}
                </p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Candidatos en proceso
                </p>
                <p class="text-2xl font-semibold">
                    {{ resumen.candidatos_en_proceso }}
                </p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">
                    Candidatos contratados
                </p>
                <p class="text-2xl font-semibold">
                    {{ resumen.candidatos_contratados }}
                </p>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <Link
                :href="indexVacantes.url()"
                class="flex items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-colors hover:border-primary/40"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]"
                >
                    <Briefcase class="size-5" />
                </span>
                <span>
                    <span class="block text-sm font-medium">Vacantes</span>
                    <span class="text-xs text-muted-foreground"
                        >Tablero por estado</span
                    >
                </span>
            </Link>

            <Link
                :href="indexCandidatos.url()"
                class="flex items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-colors hover:border-primary/40"
            >
                <span
                    class="flex size-10 items-center justify-center rounded-xl bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]"
                >
                    <UserRound class="size-5" />
                </span>
                <span>
                    <span class="block text-sm font-medium">Candidatos</span>
                    <span class="text-xs text-muted-foreground"
                        >Tablero por estado</span
                    >
                </span>
            </Link>
        </div>
    </div>
</template>
