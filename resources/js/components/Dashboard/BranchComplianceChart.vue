<script setup lang="ts">
import { computed } from 'vue';
import EmptyDashboardState from '@/components/Dashboard/EmptyDashboardState.vue';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import type { CumplimientoSucursalItem } from '@/types';

/**
 * Reemplaza la lista plana de "nombre + barrita" por un ranking visual:
 * cada sucursal como fila con posición, barra moderna y una etiqueta de
 * nivel (alto cumplimiento / en progreso / atención) calculada aquí mismo
 * a partir del porcentaje, no como un dato nuevo del backend.
 */
const props = defineProps<{
    filas: CumplimientoSucursalItem[];
}>();

const ranking = computed(() =>
    [...props.filas].sort((a, b) => b.porcentaje - a.porcentaje),
);

const COLOR_BARRA: Record<'success' | 'warning' | 'destructive', string> = {
    success: 'bg-success',
    warning: 'bg-warning',
    destructive: 'bg-destructive',
};

function nivel(porcentaje: number): {
    texto: string;
    variante: 'success' | 'warning' | 'destructive';
} {
    if (porcentaje >= 75) {
        return { texto: 'Alto cumplimiento', variante: 'success' };
    }

    if (porcentaje >= 40) {
        return { texto: 'En progreso', variante: 'warning' };
    }

    return { texto: 'Atención', variante: 'destructive' };
}
</script>

<template>
    <Card
        class="rounded-2xl border-border/60 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-lg"
    >
        <CardHeader>
            <CardTitle class="text-base">Cumplimiento por sucursal</CardTitle>
            <CardDescription
                >Ranking de tus sucursales por porcentaje de
                cumplimiento.</CardDescription
            >
        </CardHeader>
        <CardContent>
            <EmptyDashboardState
                v-if="!ranking.length"
                titulo="Sin datos de cumplimiento"
                descripcion="No hay asignaciones registradas todavía para calcular el cumplimiento por sucursal."
            />

            <div v-else class="flex flex-col gap-3">
                <div
                    v-for="(fila, indice) in ranking"
                    :key="fila.sucursal_id"
                    class="group flex flex-col gap-2.5 rounded-xl border border-transparent p-3 transition-all duration-200 hover:border-border/60 hover:bg-accent/50 sm:flex-row sm:items-center sm:gap-4"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground tabular-nums"
                    >
                        {{ indice + 1 }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div
                            class="mb-1.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                        >
                            <span class="truncate text-sm font-medium">{{
                                fila.sucursal
                            }}</span>
                            <div class="flex items-center gap-2">
                                <Badge
                                    :variant="nivel(fila.porcentaje).variante"
                                    >{{ nivel(fila.porcentaje).texto }}</Badge
                                >
                                <span class="text-sm font-semibold tabular-nums"
                                    >{{ fila.porcentaje }}%</span
                                >
                            </div>
                        </div>
                        <Progress
                            :model-value="fila.porcentaje"
                            :indicator-class="
                                COLOR_BARRA[nivel(fila.porcentaje).variante]
                            "
                        />
                        <p
                            class="mt-1 text-xs text-muted-foreground tabular-nums"
                        >
                            {{ fila.completadas }}/{{ fila.total }} asignaciones
                            completadas
                        </p>
                    </div>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
