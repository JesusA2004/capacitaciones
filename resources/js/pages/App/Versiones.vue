<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import { index } from '@/routes/app';

type Version = {
    version: string;
    buildNumber: string | null;
    changelog: string | null;
    fileSize: number | null;
    publishedAt: string | null;
};

defineProps<{
    downloadEnabled: boolean;
    historial: Version[];
}>();

function fecha(valor: string | null): string {
    if (!valor) {
        return '—';
    }

    return new Date(valor).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });
}
</script>

<template>
    <Head title="Versiones — MR. LANA PEOPLE" />

    <div class="min-h-screen bg-crema/40 px-4 py-10">
        <div class="mx-auto w-full max-w-2xl">
            <Link
                :href="index()"
                class="mb-6 inline-flex min-h-10 items-center gap-1 rounded-md text-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                <ArrowLeft class="size-4" aria-hidden="true" /> Volver a
                descarga
            </Link>

            <h1 class="mb-6 flex items-center gap-2 text-xl font-bold">
                <History class="size-5 text-primary" aria-hidden="true" />
                Historial de versiones
            </h1>

            <EmptyState
                v-if="historial.length === 0"
                :icono="History"
                titulo="Todavía no hay versiones publicadas"
                descripcion="Cuando se publique una versión de la app, aparecerá aquí con sus novedades."
                class="rounded-2xl border border-dashed bg-card"
            />

            <div v-else class="flex entrada-escalonada flex-col gap-4">
                <div
                    v-for="v in historial"
                    :key="v.version"
                    class="rounded-2xl border bg-card p-4 shadow-sm"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                    >
                        <p class="font-semibold">Versión {{ v.version }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ fecha(v.publishedAt) }}
                        </p>
                    </div>
                    <p
                        v-if="v.changelog"
                        class="mt-2 text-sm whitespace-pre-line text-muted-foreground"
                    >
                        {{ v.changelog }}
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
