<script setup lang="ts">
import { Video } from '@lucide/vue';
import EmptyDashboardState from '@/components/Dashboard/EmptyDashboardState.vue';
import type { SesionProximaItem } from '@/types';

defineProps<{
    sesiones: SesionProximaItem[];
}>();

function formatearFecha(valor: string) {
    return new Date(valor).toLocaleString('es-MX', {
        dateStyle: 'medium',
        timeStyle: 'short',
    });
}
</script>

<template>
    <div
        class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all duration-200 hover:border-primary/40 hover:shadow-md"
    >
        <h2 class="mb-3 text-sm font-semibold">Próximas sesiones en vivo</h2>

        <ul v-if="sesiones.length" class="flex flex-col gap-3">
            <li
                v-for="sesion in sesiones"
                :key="sesion.id"
                class="group flex items-start gap-2 rounded-lg p-1.5 text-sm transition-colors duration-200 hover:bg-accent"
            >
                <span
                    class="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-secondary)]/10"
                >
                    <Video class="size-3.5 text-[var(--brand-secondary)]" />
                </span>
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ sesion.titulo }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatearFecha(sesion.fecha_inicio) }}
                    </p>
                </div>
            </li>
        </ul>
        <EmptyDashboardState
            v-else
            titulo="Sin sesiones programadas"
            descripcion="Cuando se programe una sesión en vivo aparecerá aquí."
        />
    </div>
</template>
