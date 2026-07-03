<script setup lang="ts">
import { Video } from '@lucide/vue';
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
    <div class="rounded-xl border p-4">
        <h2 class="mb-3 text-sm font-semibold">Próximas sesiones en vivo</h2>

        <ul v-if="sesiones.length" class="flex flex-col gap-3">
            <li
                v-for="sesion in sesiones"
                :key="sesion.id"
                class="flex items-start gap-2 text-sm"
            >
                <Video class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                <div class="min-w-0">
                    <p class="truncate font-medium">{{ sesion.titulo }}</p>
                    <p class="text-xs text-muted-foreground">
                        {{ formatearFecha(sesion.fecha_inicio) }}
                    </p>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">
            No hay sesiones en vivo programadas.
        </p>
    </div>
</template>
