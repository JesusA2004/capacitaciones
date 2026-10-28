<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import BannerNotificacionesEscritorio from '@/components/BannerNotificacionesEscritorio.vue';
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
        <!-- Margen inferior: barra móvil (4rem) + botón de ayuda flotante
             (BotonAyuda), para que al final de la página nunca tape nada. -->
        <AppContent
            variant="sidebar"
            class="overflow-x-hidden pb-[calc(7rem+env(safe-area-inset-bottom))] sm:pb-16"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <BannerNotificacionesEscritorio />
            <slot />
        </AppContent>
        <MobileBottomNav />
        <Toaster />
        <BotonAyuda />
        <TourGuiado />
    </AppShell>
</template>
