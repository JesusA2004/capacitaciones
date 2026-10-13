<script setup lang="ts">
import { CheckCircle2, CircleDashed } from '@lucide/vue';
import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import type { PreparacionVersion } from '@/types';

/**
 * ¿Se puede activar esta versión? Barra + cinco chequeos (campos, datos,
 * fuentes, diseño, prueba) y, si falta algo, la lista corta en lenguaje
 * humano. El detalle técnico vive en «Más información».
 */
defineProps<{ preparacion: PreparacionVersion }>();
</script>

<template>
    <div class="flex flex-col gap-3">
        <div class="flex items-center justify-between text-sm">
            <span class="font-medium">Preparación</span>
            <span class="font-semibold tabular-nums">{{ preparacion.porcentaje }} %</span>
        </div>
        <Progress :model-value="preparacion.porcentaje" class="h-2" />
        <ul class="flex flex-col gap-1.5">
            <li
                v-for="c in preparacion.chequeos"
                :key="c.clave"
                :class="cn('flex items-start gap-2 text-sm', c.ok ? 'text-foreground' : 'text-muted-foreground')"
            >
                <CheckCircle2 v-if="c.ok" class="mt-0.5 size-4 shrink-0 text-success" />
                <CircleDashed v-else class="mt-0.5 size-4 shrink-0 text-warning" />
                <span>
                    {{ c.etiqueta }}
                    <span v-if="c.detalle && !c.ok" class="block text-xs">{{ c.detalle }}</span>
                </span>
            </li>
        </ul>
        <div v-if="preparacion.faltan.length && !preparacion.activo" class="rounded-lg bg-warning-soft px-3 py-2 text-xs text-warning-foreground">
            <p class="font-semibold">No puedes activar este formato todavía</p>
            <ul class="mt-1 list-disc pl-4">
                <li v-for="f in preparacion.faltan" :key="f">{{ f }}</li>
            </ul>
        </div>
    </div>
</template>
