<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { BadgeVariants } from '@/components/ui/badge';
import type { EstadoEjecutivoMaster } from '@/types';

/**
 * Estado ejecutivo de un documento maestro: LISTO / REQUIERE REVISIÓN /
 * BLOQUEADO / SIN FORMATO / REFERENCIA.
 */
defineProps<{ estado: EstadoEjecutivoMaster }>();

const ESTADOS: Record<
    EstadoEjecutivoMaster,
    { etiqueta: string; variante: NonNullable<BadgeVariants['variant']> }
> = {
    listo: { etiqueta: 'Listo', variante: 'success' },
    requiere_revision: { etiqueta: 'Requiere revisión', variante: 'warning' },
    bloqueado: { etiqueta: 'Bloqueado', variante: 'destructive' },
    sin_formato: { etiqueta: 'Sin formato', variante: 'destructive' },
    referencia: { etiqueta: 'Referencia', variante: 'outline' },
};
</script>

<template>
    <Badge :variant="ESTADOS[estado].variante" class="uppercase">{{
        ESTADOS[estado].etiqueta
    }}</Badge>
</template>
