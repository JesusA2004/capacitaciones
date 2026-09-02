<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Rocket } from '@lucide/vue';
import { dashboard } from '@/routes';

const props = defineProps<{
    titulo: string;
    descripcion: string;
    fase?: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: props.titulo, href: '' },
        ],
    },
});
</script>

<template>
    <Head :title="titulo" />

    <div
        class="flex flex-1 flex-col items-center justify-center gap-6 p-6 text-center"
    >
        <span
            class="flex size-16 items-center justify-center rounded-3xl bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]"
        >
            <Rocket class="size-8" />
        </span>

        <div class="space-y-2">
            <span
                class="inline-flex items-center rounded-full bg-warning/10 px-3 py-1 text-xs font-medium text-warning"
            >
                Próximamente
            </span>
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ titulo }}
            </h1>
            <p class="max-w-md text-sm text-muted-foreground">
                {{ descripcion }}
            </p>
            <p v-if="fase" class="text-xs text-muted-foreground">
                Planeado para {{ fase }}.
            </p>
        </div>

        <Link
            :href="dashboard()"
            class="text-sm font-medium text-[var(--brand-primary)] underline-offset-4 hover:underline"
        >
            Volver al inicio
        </Link>
    </div>
</template>
