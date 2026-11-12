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

// Posición del acceso activo (−1 = la pantalla actual no está en la barra):
// la pastilla de fondo se desliza hasta ahí en vez de saltar.
const indiceActivo = computed(() =>
    accesosDirectos.value.findIndex((item) => isCurrentUrl(item.href)),
);
const columnas = computed(() => accesosDirectos.value.length + 1);

// Etiqueta corta para la barra (5 columnas en ~390 px): sin el «Mi/Mis»
// del menú lateral — «Mis notificaciones» → «Notificaciones».
function etiquetaCorta(titulo: string): string {
    const corta = titulo.replace(/^(Mi|Mis)\s+/u, '');

    return corta.charAt(0).toUpperCase() + corta.slice(1);
}
</script>

<template>
    <nav
        aria-label="Accesos rápidos"
        class="fixed inset-x-0 bottom-0 z-40 flex items-stretch justify-around border-t border-border/60 bg-background/95 pb-[env(safe-area-inset-bottom)] shadow-[0_-4px_16px_-8px_rgb(0_0_0_/_0.15)] backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:hidden"
    >
        <!-- Pastilla del acceso activo: una sola, se desliza entre columnas
             (todas miden lo mismo, así que basta translateX por índice). -->
        <span
            aria-hidden="true"
            class="pastilla-activa pointer-events-none absolute top-0 left-0 flex flex-col items-center pt-1.5"
            :class="{ 'pastilla-activa--visible': indiceActivo >= 0 }"
            :style="{
                width: `${100 / columnas}%`,
                transform: `translateX(${Math.max(indiceActivo, 0) * 100}%)`,
            }"
        >
            <span
                class="absolute top-0 h-[3px] w-8 rounded-b-full bg-oro"
            ></span>
            <span
                class="size-9 rounded-full bg-[var(--brand-primary)]/10"
            ></span>
        </span>

        <Link
            v-for="(item, indice) in accesosDirectos"
            :key="item.title"
            :href="item.href"
            :aria-current="indice === indiceActivo ? 'page' : undefined"
            class="group relative flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[0.7rem] font-medium transition-colors"
            :class="
                indice === indiceActivo
                    ? 'text-[var(--brand-primary)]'
                    : 'text-muted-foreground'
            "
        >
            <span
                class="flex size-9 items-center justify-center rounded-full transicion-ui group-active:scale-90 motion-reduce:group-active:scale-100"
            >
                <component
                    :is="item.icon"
                    class="size-5 transition-transform duration-200"
                    :class="{ 'scale-110': indice === indiceActivo }"
                    aria-hidden="true"
                />
            </span>
            <span
                class="w-full truncate text-center text-[0.65rem] leading-tight tracking-tight"
                :title="item.title"
                >{{ etiquetaCorta(item.title) }}</span
            >
        </Link>

        <button
            type="button"
            aria-label="Abrir el menú completo"
            class="group relative flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[0.7rem] font-medium text-muted-foreground"
            @click="setOpenMobile(true)"
        >
            <span
                class="flex size-9 items-center justify-center rounded-full transicion-ui group-active:scale-90 motion-reduce:group-active:scale-100"
            >
                <Menu class="size-5" aria-hidden="true" />
            </span>
            <span class="text-[0.65rem] leading-tight tracking-tight"
                >Menú</span
            >
        </button>
    </nav>
</template>

<style scoped>
.pastilla-activa {
    opacity: 0;
    transition:
        opacity var(--mov-base) var(--curva-salida),
        transform var(--mov-lento) var(--curva-salida);
}

.pastilla-activa--visible {
    opacity: 1;
}

@media (prefers-reduced-motion: reduce) {
    .pastilla-activa {
        transition: none;
    }
}

.sin-animaciones .pastilla-activa {
    transition: none;
}
</style>
