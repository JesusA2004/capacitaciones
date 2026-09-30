<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import MobileBottomNav from '@/components/MobileBottomNav.vue';
import BotonAyuda from '@/components/sistema/BotonAyuda.vue';
import TourGuiado from '@/components/sistema/TourGuiado.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent
            variant="sidebar"
            class="overflow-x-hidden pb-[calc(4rem+env(safe-area-inset-bottom))] sm:pb-0"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <slot />
        </AppContent>
        <MobileBottomNav />
        <Toaster />
        <BotonAyuda />
        <TourGuiado />
    </AppShell>
</template>
