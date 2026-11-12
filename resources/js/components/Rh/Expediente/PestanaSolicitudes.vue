<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ClipboardList } from '@lucide/vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showSolicitud } from '@/routes/rh/solicitudes';
import type { SolicitudExpedienteItem } from '@/types';

defineProps<{
    solicitudes: SolicitudExpedienteItem[];
}>();
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <ClipboardList class="size-4" />
                Solicitudes
            </CardTitle>
        </CardHeader>
        <CardContent class="flex entrada-escalonada flex-col gap-2">
            <Link
                v-for="solicitud in solicitudes"
                :key="solicitud.id"
                :href="showSolicitud.url(solicitud.id)"
                class="flex fila-interactiva flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 p-3 text-sm"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ solicitud.folio }} ·
                        {{ solicitud.tipo_etiqueta }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ solicitud.motivo }}
                    </p>
                </div>
                <EstadoBadge :estado="solicitud.estado" />
            </Link>
            <EmptyState
                v-if="!solicitudes.length"
                :icono="ClipboardList"
                titulo="Sin solicitudes registradas"
                descripcion="Las solicitudes de este colaborador aparecerán aquí."
            />
        </CardContent>
    </Card>
</template>
