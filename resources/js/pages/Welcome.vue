<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { dashboard, login } from '@/routes';
</script>

<template>
    <Head title="Bienvenido" />

    <div
        class="flex min-h-screen flex-col items-center justify-center bg-background p-6 text-foreground"
    >
        <div
            class="flex w-full max-w-md flex-col items-center gap-6 text-center"
        >
            <span
                class="text-3xl font-bold tracking-tight text-[var(--brand-foreground)]"
            >
                Mr. <span class="text-[var(--brand-primary)]">Lana</span>
            </span>

            <div class="space-y-2">
                <h1 class="text-xl font-semibold">Portal de Capacitación</h1>
                <p class="text-sm text-muted-foreground">
                    Inducción, capacitaciones obligatorias, evaluaciones y
                    seguimiento para colaboradores de Mr. Lana.
                </p>
            </div>

            <Link
                v-if="$page.props.auth.user"
                :href="dashboard()"
                class="inline-flex h-10 items-center justify-center rounded-md bg-[var(--brand-primary)] px-6 text-sm font-medium text-[var(--brand-foreground)] transition-colors hover:opacity-90"
            >
                Ir a mi capacitación
            </Link>
            <Link
                v-else
                :href="login()"
                class="inline-flex h-10 items-center justify-center rounded-md bg-[var(--brand-primary)] px-6 text-sm font-medium text-[var(--brand-foreground)] transition-colors hover:opacity-90"
            >
                Iniciar sesión
            </Link>
        </div>
    </div>
</template>
