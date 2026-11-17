<script setup lang="ts">
import { Award, Medal, Trophy } from '@lucide/vue';
import { computed } from 'vue';
import type { NivelCertificacionClave } from '@/types';

/**
 * Insignia de un nivel de la Certificación Mr. Lana. `bloqueada` la pinta
 * apagada (nivel aún no alcanzado).
 */
const props = withDefaults(
    defineProps<{
        nivel: NivelCertificacionClave;
        etiqueta?: string;
        bloqueada?: boolean;
        tamano?: 'sm' | 'lg';
    }>(),
    { etiqueta: '', bloqueada: false, tamano: 'sm' },
);

const ESTILOS: Record<
    NivelCertificacionClave,
    { clase: string; icono: typeof Award }
> = {
    bronce: { clase: 'bg-crema text-bronce ring-bronce/30', icono: Medal },
    plata: { clase: 'bg-salvia text-primary ring-primary/25', icono: Award },
    oro: { clase: 'bg-oro/20 text-bronce ring-oro/50', icono: Trophy },
};

const estilo = computed(() => ESTILOS[props.nivel]);
</script>

<template>
    <span
        class="inline-flex items-center gap-2 rounded-full font-semibold ring-1 transicion-ui"
        :class="[
            estilo.clase,
            tamano === 'lg' ? 'px-4 py-2 text-sm' : 'px-2.5 py-1 text-xs',
            bloqueada ? 'opacity-40 grayscale' : 'aparece-zoom',
        ]"
    >
        <component
            :is="estilo.icono"
            :class="tamano === 'lg' ? 'size-5' : 'size-3.5'"
            aria-hidden="true"
        />
        <span v-if="etiqueta">{{ etiqueta }}</span>
    </span>
</template>
