<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import BannerInstalarApp from '@/components/BannerInstalarApp.vue';
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
             (BotonAyuda), para que al final de la página nunca tape nada.
             `min-w-0` deja que el contenido se encoja junto al sidebar;
             `overflow-x-clip` (NO `hidden`): `hidden` convertía el <main> en
             contenedor de scroll y el encabezado `sticky` dejaba de quedarse
             arriba al bajar. Cada tabla/carrusel ancho trae su propio
             scroll horizontal; esto es solo red de seguridad. -->
        <AppContent
            variant="sidebar"
            class="min-w-0 overflow-x-clip pb-[calc(7rem+env(safe-area-inset-bottom))] sm:pb-16"
        >
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <BannerNotificacionesEscritorio />
            <BannerInstalarApp />
            <slot />
        </AppContent>
        <MobileBottomNav />
        <Toaster />
        <BotonAyuda />
        <TourGuiado />
    </AppShell>
</template>
