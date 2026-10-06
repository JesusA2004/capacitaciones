<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { FileStack, Images, Receipt } from '@lucide/vue';
import { index as indexJuridicos } from '@/routes/rh/documentos-maestros';
import { index as indexAdministrativos } from '@/routes/rh/documentos-maestros/administrativos';
import { index as indexFondos } from '@/routes/rh/documentos-maestros/fondos';

/**
 * Las tres secciones de Administración → Documentos maestros:
 *  1. Plantillas jurídicas (contratos, convenios… DOCX / PDF overlay);
 *  2. Documentos administrativos (recibo de nómina, finiquito, comprobante,
 *     constancia: HTML → PDF con Chrome o DomPDF);
 *  3. Fondos y recursos (fondos, logos, sellos, marcas de agua).
 */
defineProps<{
    actual: 'juridicos' | 'administrativos' | 'recursos';
}>();

const SECCIONES = [
    {
        clave: 'juridicos',
        titulo: 'Plantillas jurídicas',
        href: indexJuridicos.url(),
        icono: FileStack,
    },
    {
        clave: 'administrativos',
        titulo: 'Documentos administrativos',
        href: indexAdministrativos.url(),
        icono: Receipt,
    },
    {
        clave: 'recursos',
        titulo: 'Fondos y recursos',
        href: indexFondos.url(),
        icono: Images,
    },
] as const;
</script>

<template>
    <nav
        class="flex flex-wrap gap-1 rounded-xl border bg-muted/40 p-1"
        aria-label="Secciones de documentos maestros"
    >
        <Link
            v-for="seccion in SECCIONES"
            :key="seccion.clave"
            :href="seccion.href"
            class="flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all"
            :class="
                actual === seccion.clave
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground hover:bg-background/60 hover:text-foreground'
            "
            :aria-current="actual === seccion.clave ? 'page' : undefined"
        >
            <component :is="seccion.icono" class="size-4" />
            {{ seccion.titulo }}
        </Link>
    </nav>
</template>
