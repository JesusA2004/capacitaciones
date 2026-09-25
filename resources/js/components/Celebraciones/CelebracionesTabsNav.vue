<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { usePermisos } from '@/composables/usePermisos';
import { cn } from '@/lib/utils';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';

/**
 * Celebraciones = Cumpleaños + Aniversarios: mismas acciones y la misma
 * infraestructura; cada pestaña es su propia ruta (docs/CELEBRACIONES.md).
 */
defineProps<{ activa: 'cumpleanos' | 'aniversarios' }>();

const { tienePermiso } = usePermisos();

const clase = (activo: boolean) =>
    cn(
        'inline-flex shrink-0 items-center rounded-lg px-3 py-1 text-sm font-medium transition-all',
        activo ? 'bg-background text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
    );
</script>

<template>
    <nav class="inline-flex h-9 w-fit max-w-full gap-1 overflow-x-auto rounded-xl bg-muted p-1" aria-label="Celebraciones">
        <Link v-if="tienePermiso('rh.cumpleanos.ver')" :href="indexCumpleanos()" :class="clase(activa === 'cumpleanos')">Cumpleaños</Link>
        <Link v-if="tienePermiso('celebraciones.ver')" :href="indexAniversarios()" :class="clase(activa === 'aniversarios')">Aniversarios</Link>
    </nav>
</template>
