<script setup lang="ts">
import type { ResumenCumplimiento } from '@/types';

defineProps<{
    resumen: ResumenCumplimiento;
}>();
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border p-4">
            <p class="text-xs text-muted-foreground">Cumplimiento general</p>
            <p class="mt-1 text-2xl font-semibold">
                {{ resumen.porcentaje_cumplimiento }}%
            </p>
        </div>
        <div class="rounded-xl border p-4">
            <p class="text-xs text-muted-foreground">
                Asignaciones completadas
            </p>
            <p class="mt-1 text-2xl font-semibold">
                {{ resumen.completadas }} / {{ resumen.total_asignaciones }}
            </p>
        </div>
        <div class="rounded-xl border p-4">
            <p class="text-xs text-muted-foreground">Asignaciones vencidas</p>
            <p class="mt-1 text-2xl font-semibold text-destructive">
                {{ resumen.vencidas }}
            </p>
        </div>
    </div>
</template>
