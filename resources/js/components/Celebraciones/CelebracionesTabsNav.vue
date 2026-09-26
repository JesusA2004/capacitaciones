<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Award, Cake } from '@lucide/vue';
import { usePermisos } from '@/composables/usePermisos';
import { cn } from '@/lib/utils';
import { index as indexAniversarios } from '@/routes/rh/aniversarios';
import { index as indexCumpleanos } from '@/routes/rh/cumpleanos';

/**
 * Subnavegación de Celebraciones = Cumpleaños | Aniversarios (cada pestaña
 * es su propia ruta, docs/CELEBRACIONES.md) + barra de herramientas de la
 * página en la MISMA fila (buscador, filtros, configurar). Es lo primero de
 * la página: no hay título ni descripción encima que dejen un hueco.
 */
defineProps<{ activa: 'cumpleanos' | 'aniversarios' }>();

const { tienePermiso } = usePermisos();

const clase = (activo: boolean) =>
    cn(
        '-mb-px inline-flex h-10 shrink-0 items-center gap-1.5 border-b-2 px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
        activo ? 'border-primary text-foreground' : 'border-transparent text-muted-foreground hover:text-foreground',
    );
</script>

<template>
    <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2 border-b">
        <nav class="flex max-w-full overflow-x-auto" aria-label="Celebraciones">
            <Link
                v-if="tienePermiso('rh.cumpleanos.ver')"
                :href="indexCumpleanos()"
                :class="clase(activa === 'cumpleanos')"
                :aria-current="activa === 'cumpleanos' ? 'page' : undefined"
            >
                <Cake class="size-4" />Cumpleaños
            </Link>
            <Link
                v-if="tienePermiso('celebraciones.ver')"
                :href="indexAniversarios()"
                :class="clase(activa === 'aniversarios')"
                :aria-current="activa === 'aniversarios' ? 'page' : undefined"
            >
                <Award class="size-4" />Aniversarios
            </Link>
        </nav>
        <div v-if="$slots.default" class="flex w-full min-w-0 items-center justify-end gap-2 pb-2 sm:w-auto">
            <slot />
        </div>
    </div>
</template>
