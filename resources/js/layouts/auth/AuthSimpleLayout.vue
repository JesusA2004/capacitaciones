<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { home } from '@/routes';

defineProps<{
    title?: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex min-h-svh flex-col items-center justify-center gap-6 overflow-hidden bg-background p-4 sm:p-6 md:p-10"
    >
        <!-- Fondo con gradiente suave de marca; puramente decorativo. -->
        <div
            aria-hidden="true"
            class="pointer-events-none absolute inset-0 -z-10 bg-[radial-gradient(ellipse_80%_60%_at_50%_-10%,color-mix(in_oklab,var(--brand-primary)_18%,transparent),transparent),radial-gradient(ellipse_60%_50%_at_100%_100%,color-mix(in_oklab,var(--brand-secondary)_14%,transparent),transparent)]"
        />

        <div class="flex w-full max-w-sm flex-col gap-6">
            <Link
                :href="home()"
                class="flex flex-col items-center gap-3 font-medium"
            >
                <div
                    class="flex size-16 items-center justify-center rounded-2xl bg-[var(--brand-primary)] text-[var(--brand-foreground)] shadow-[var(--brand-primary)]/20 shadow-lg"
                >
                    <AppLogoIcon class="size-9 fill-current" />
                </div>
                <span
                    class="text-lg font-semibold tracking-tight text-foreground"
                >
                    MR. LANA
                    <span class="text-[var(--brand-primary)]">PEOPLE</span>
                </span>
            </Link>

            <div
                class="rounded-3xl border border-border/60 bg-card/95 p-6 shadow-xl shadow-black/5 backdrop-blur-sm sm:p-8"
            >
                <div class="mb-6 space-y-1.5 text-center">
                    <h1 class="text-xl font-semibold tracking-tight">
                        {{ title }}
                    </h1>
                    <p class="text-sm text-muted-foreground">
                        {{ description }}
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </div>
</template>
