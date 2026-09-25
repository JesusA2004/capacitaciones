<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePermisos } from '@/composables/usePermisos';
import { cn } from '@/lib/utils';
import { index as indexOficiales } from '@/routes/rh/formatos';
import { index as indexWordGenerados } from '@/routes/rh/formatos/catalogo';
import { generados, variables } from '@/routes/rh/formatos-oficiales';
import { index as indexPlantillas } from '@/routes/rh/plantillas';

/**
 * Pestañas de la pantalla "Formatos" (docs/FORMATOS_OFICIALES.md). Cada
 * pestaña es una ruta Inertia distinta (navegar es un <Link>, no estado
 * local). Las dos últimas son el motor Word por claves que usan contratos,
 * pagarés y actas (docs/PLANTILLAS_FORMATOS.md), solo para quien administra
 * plantillas.
 */
const props = defineProps<{
    activa: 'oficiales' | 'generados' | 'variables' | 'plantillas' | 'word-generados';
}>();

const { tienePermiso } = usePermisos();

const puedeVerOficiales = computed(() => tienePermiso('formatos_oficiales.ver'));
const puedeVerPlantillas = computed(() => tienePermiso('plantillas.ver'));

function claseTab(activo: boolean): string {
    return cn(
        'inline-flex shrink-0 items-center justify-center gap-1.5 whitespace-nowrap rounded-lg px-3 py-1 text-sm font-medium transition-all',
        activo
            ? 'bg-background text-foreground shadow-sm'
            : 'text-muted-foreground hover:text-foreground',
    );
}
</script>

<template>
    <nav
        class="inline-flex h-9 w-fit max-w-full items-center justify-start gap-1 overflow-x-auto rounded-xl bg-muted p-1 text-muted-foreground"
        aria-label="Secciones de Formatos"
        data-tour="formatos-pestanas"
    >
        <template v-if="puedeVerOficiales">
            <Link :href="indexOficiales()" :class="claseTab(props.activa === 'oficiales')">Plantillas oficiales</Link>
            <Link :href="generados()" :class="claseTab(props.activa === 'generados')">Documentos generados</Link>
            <Link :href="variables()" :class="claseTab(props.activa === 'variables')">Variables</Link>
        </template>
        <template v-if="puedeVerPlantillas">
            <Link :href="indexPlantillas()" :class="claseTab(props.activa === 'plantillas')">Plantillas Word por clave</Link>
            <Link :href="indexWordGenerados()" :class="claseTab(props.activa === 'word-generados')">Generados (Word)</Link>
        </template>
    </nav>
</template>
