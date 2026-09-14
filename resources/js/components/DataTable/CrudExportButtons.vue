<script setup lang="ts">
import { FileSpreadsheet, FileText } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * Botones de exportar Excel/PDF con la identidad de cada formato (verde
 * Excel, rojo PDF) en vez del mismo botón gris genérico repetido en cada
 * listado — ver docs/PRUEBAS_MANUALES.md, checklist visual de cierre.
 */
defineProps<{
    urlExcel: string;
    urlPdf: string;
}>();
</script>

<template>
    <Button as-child variant="outline-success" size="sm">
        <a :href="urlExcel">
            <FileSpreadsheet class="size-4" />
            Excel
        </a>
    </Button>
    <Button as-child variant="outline-destructive" size="sm">
        <a :href="urlPdf">
            <FileText class="size-4" />
            PDF
        </a>
    </Button>
</template>
