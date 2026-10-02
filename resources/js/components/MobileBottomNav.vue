<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Menu } from '@lucide/vue';
import { computed } from 'vue';
import { useSidebar } from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useMainNavItems } from '@/composables/useMainNavItems';

/**
 * Barra de navegación inferior, solo visible en móvil (< sm): muestra los
 * primeros accesos del modo actual (colaborador/operativo, ver
 * useMainNavItems()) como atajos directos y un botón "Menú" que abre el
 * mismo Sheet lateral completo que ya usa el sidebar en móvil
 * (components/ui/sidebar/Sidebar.vue) — nunca se duplica la navegación
 * completa en dos componentes distintos.
 */
const { mainNavItems } = useMainNavItems();
const { setOpenMobile } = useSidebar();
const { isCurrentUrl } = useCurrentUrl();

const MAX_ACCESOS_DIRECTOS = 4;
const accesosDirectos = computed(() =>
    mainNavItems.value.slice(0, MAX_ACCESOS_DIRECTOS),
);
</script>

<template>
    <nav
        class="fixed inset-x-0 bottom-0 z-40 flex items-stretch justify-around border-t border-border/60 bg-background/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_16px_-8px_rgb(0_0_0_/_0.15)] backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:hidden"
    >
        <Link
            v-for="item in accesosDirectos"
            :key="item.title"
            :href="item.href"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[0.65rem] font-medium"
            :class="
                isCurrentUrl(item.href)
                    ? 'text-[var(--brand-primary)]'
                    : 'text-muted-foreground'
            "
        >
            <span
                class="flex size-9 items-center justify-center rounded-full transition-colors"
                :class="
                    isCurrentUrl(item.href)
                        ? 'bg-[var(--brand-primary)]/10'
                        : ''
                "
            >
                <component :is="item.icon" class="size-5" />
            </span>
            <span class="truncate">{{ item.title }}</span>
        </Link>

        <button
            type="button"
            class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[0.65rem] font-medium text-muted-foreground"
            @click="setOpenMobile(true)"
        >
            <span class="flex size-9 items-center justify-center rounded-full">
                <Menu class="size-5" />
            </span>
            <span>Menú</span>
        </button>
    </nav>
</template>
