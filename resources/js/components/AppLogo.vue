<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
</script>

<template>
    <div
        class="flex aspect-square size-8 items-center justify-center rounded-md bg-[var(--brand-primary)] text-[var(--brand-foreground)]"
    >
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="mb-0.5 truncate leading-tight font-semibold">
            Mr. <span class="text-[var(--brand-primary)]">Lana</span>
        </span>
        <span class="truncate text-xs text-muted-foreground"
            >Portal de Colaboradores y RH</span
        >
    </div>
</template>
