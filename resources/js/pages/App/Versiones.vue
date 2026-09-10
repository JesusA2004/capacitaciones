<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, History } from '@lucide/vue';
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

    <div class="min-h-screen bg-muted/30 px-4 py-10">
        <div class="mx-auto w-full max-w-2xl">
            <Link
                :href="index()"
                class="mb-6 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
            >
                <ArrowLeft class="size-4" /> Volver a descarga
            </Link>

            <h1 class="mb-6 flex items-center gap-2 text-xl font-bold">
                <History class="size-5" /> Historial de versiones
            </h1>

            <div
                v-if="historial.length === 0"
                class="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground"
            >
                Todavía no hay versiones publicadas.
            </div>

            <div v-else class="flex flex-col gap-4">
                <div
                    v-for="v in historial"
                    :key="v.version"
                    class="rounded-xl border bg-background p-4"
                >
                    <div class="flex items-center justify-between">
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
