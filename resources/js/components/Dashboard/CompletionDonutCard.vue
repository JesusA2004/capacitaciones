<script setup lang="ts">
import { CheckCircle2 } from '@lucide/vue';
import { computed } from 'vue';
import ProgressRing from '@/components/Dashboard/ProgressRing.vue';
import { Progress } from '@/components/ui/progress';

/**
 * "Asignaciones completadas": un mini-anillo (el "gráfico" pedido) + una
 * barra de progreso moderna, en vez del antiguo "6 / 18" en texto plano.
 */
const props = defineProps<{
    completadas: number;
    total: number;
    porcentaje: number;
}>();

const pendientes = computed(() => Math.max(0, props.total - props.completadas));
</script>

<template>
    <div
        class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-lg"
    >
        <div class="flex items-center justify-between gap-3">
            <div class="flex items-center gap-2.5">
                <span
                    class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-success/10 text-success"
                >
                    <CheckCircle2 class="size-4.5" />
                </span>
                <p class="text-sm font-medium text-muted-foreground">
                    Asignaciones completadas
                </p>
            </div>
            <ProgressRing :porcentaje="porcentaje" :tamano="44" :grosor="5" />
        </div>

        <div>
            <p class="text-2xl font-semibold tabular-nums">
                {{ completadas }} completadas
            </p>
            <p class="text-xs text-muted-foreground">
                {{ pendientes }} pendientes por terminar
            </p>
        </div>

        <div class="flex flex-col gap-1.5">
            <Progress :model-value="porcentaje" />
            <p class="text-xs font-medium text-muted-foreground tabular-nums">
                {{ porcentaje }}% de avance
            </p>
        </div>
    </div>
</template>
