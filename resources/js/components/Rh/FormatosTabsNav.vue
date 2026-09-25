<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { usePermisos } from '@/composables/usePermisos';
import { cn } from '@/lib/utils';
import { index as indexOficiales } from '@/routes/rh/formatos';
import { index as indexGenerados } from '@/routes/rh/formatos/catalogo';
import { index as indexPlantillas } from '@/routes/rh/plantillas';

/**
 * Barra de tabs de la pantalla unificada "Formatos" (ver
 * docs/PLANTILLAS_FORMATOS.md). Cada tab es en realidad una ruta Inertia
 * distinta -- navegar entre tabs es un <Link>, no estado local -- para no
 * duplicar los controladores/paginación de cada sección.
 */
const props = defineProps<{
    activa: 'oficiales' | 'plantillas' | 'generados';
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
        <Link
            v-if="puedeVerOficiales"
            :href="indexOficiales()"
            :class="claseTab(props.activa === 'oficiales')"
        >
            Oficiales PDF
        </Link>
        <Link
            v-if="puedeVerPlantillas"
            :href="indexPlantillas()"
            :class="claseTab(props.activa === 'plantillas')"
        >
            Plantillas avanzadas DOCX
        </Link>
        <Link
            v-if="puedeVerPlantillas"
            :href="indexGenerados()"
            :class="claseTab(props.activa === 'generados')"
        >
            Documentos generados
        </Link>
    </nav>
</template>
