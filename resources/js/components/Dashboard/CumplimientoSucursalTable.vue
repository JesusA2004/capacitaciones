<script setup lang="ts">
import type { CumplimientoSucursalItem } from '@/types';

defineProps<{
    filas: CumplimientoSucursalItem[];
}>();
</script>

<template>
    <div class="rounded-xl border p-4">
        <h2 class="mb-3 text-sm font-semibold">Cumplimiento por sucursal</h2>

        <div v-if="filas.length" class="flex flex-col gap-2">
            <div
                v-for="fila in filas"
                :key="fila.sucursal_id"
                class="flex items-center justify-between gap-3 text-sm"
            >
                <span class="truncate">{{ fila.sucursal }}</span>
                <div class="flex items-center gap-2">
                    <div class="h-2 w-32 overflow-hidden rounded-full bg-muted">
                        <div
                            class="h-full bg-[var(--brand-primary)]"
                            :style="{ width: `${fila.porcentaje}%` }"
                        />
                    </div>
                    <span class="w-12 text-right text-xs text-muted-foreground"
                        >{{ fila.porcentaje }}%</span
                    >
                </div>
            </div>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            No hay datos de cumplimiento disponibles todavía.
        </p>
    </div>
</template>
