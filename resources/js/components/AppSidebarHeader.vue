<script setup lang="ts">
import Breadcrumbs from '@/components/Breadcrumbs.vue';
import NotificationBell from '@/components/NotificationBell.vue';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItem } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItem[];
    }>(),
    {
        breadcrumbs: () => [],
    },
);
</script>

<template>
    <header
        class="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4"
    >
        <div class="flex flex-1 items-center justify-between gap-2">
            <div class="flex min-w-0 items-center gap-2">
                <SidebarTrigger class="-ml-1" />
                <template v-if="breadcrumbs && breadcrumbs.length > 0">
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </template>
            </div>
            <!-- Destino de las acciones de módulo (CrudPageHeader) en md+:
                 así un botón como «Nueva solicitud» no ocupa una fila entera
                 de espacio vacío arriba del contenido. -->
            <div id="acciones-pagina" class="ml-auto hidden min-w-0 items-center justify-end gap-2 md:flex" />
            <NotificationBell />
        </div>
    </header>
</template>
