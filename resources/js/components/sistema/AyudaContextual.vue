<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { CircleHelp } from '@lucide/vue';
import { computed } from 'vue';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { articulo } from '@/lib/guia';
import { articuloDePantalla } from '@/lib/guia/contextual';

/**
 * «¿Cómo funciona?» en la barra superior de las pantallas importantes
 * (Vacantes, Campañas, Candidatos, Expediente, Solicitudes, Nómina,
 * Onboarding…): abre el artículo de la Guía que explica esa pantalla. El
 * mapeo vive en resources/js/lib/guia/contextual.ts.
 */
const { currentUrl } = useCurrentUrl();

const art = computed(() => {
    const id = articuloDePantalla(currentUrl.value);

    return id ? articulo(id) : undefined;
});
</script>

<template>
    <Link
        v-if="art"
        :href="`/ayuda/articulo/${art.id}`"
        data-tour="ayuda-contextual"
        class="inline-flex shrink-0 items-center gap-1.5 rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-2.5 py-1 text-xs font-medium text-[var(--mrl-texto-suave)] transition hover:border-oro hover:text-[var(--mrl-texto)]"
        :title="`Guía: ${art.titulo}`"
        :aria-label="`¿Cómo funciona? ${art.titulo}`"
    >
        <CircleHelp class="size-4 text-oro" />
        <span class="hidden sm:inline">¿Cómo funciona?</span>
    </Link>
</template>
