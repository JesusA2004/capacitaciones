<script setup lang="ts">
import { computed } from 'vue';
import ProgressRing from '@/components/Dashboard/ProgressRing.vue';
import { Badge } from '@/components/ui/badge';

/**
 * La pieza central del hero del dashboard: el cumplimiento general como
 * anillo grande en vez de un simple "33.3%" en texto plano. El estado
 * cualitativo (en buen camino / necesita atención / crítico) sustituye a
 * una tendencia numérica que hoy no calculamos (no comparamos contra un
 * periodo anterior en el backend); es honesto mostrar un estado, no
 * inventar una variación.
 */
const props = defineProps<{
    porcentaje: number;
    completadas: number;
    total: number;
}>();

const estado = computed<{
    texto: string;
    variante: 'success' | 'warning' | 'destructive';
}>(() => {
    if (props.porcentaje >= 75) {
        return { texto: 'En buen camino', variante: 'success' };
    }

    if (props.porcentaje >= 40) {
        return { texto: 'Necesita atención', variante: 'warning' };
    }

    return { texto: 'Crítico', variante: 'destructive' };
});
</script>

<template>
    <div
        class="group relative flex flex-col items-center gap-5 overflow-hidden rounded-2xl border border-border/60 bg-card/80 p-6 text-center shadow-sm backdrop-blur transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg sm:flex-row sm:text-left"
    >
        <div
            class="pointer-events-none absolute -top-10 -right-10 size-40 rounded-full bg-primary/10 blur-2xl transition-opacity duration-300 group-hover:opacity-80"
        />

        <ProgressRing
            :porcentaje="porcentaje"
            :tamano="132"
            :grosor="12"
            class="shrink-0"
        />

        <div class="relative flex flex-col items-center gap-2 sm:items-start">
            <p class="text-sm font-medium text-muted-foreground">
                Cumplimiento general
            </p>
            <p class="text-3xl font-semibold tabular-nums">{{ porcentaje }}%</p>
            <p class="text-sm text-muted-foreground">
                {{ completadas }} de {{ total }} asignaciones completadas
            </p>
            <Badge :variant="estado.variante">{{ estado.texto }}</Badge>
        </div>
    </div>
</template>
