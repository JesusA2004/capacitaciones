<script setup lang="ts">
import CelebracionesHoyCard from '@/components/Celebraciones/CelebracionesHoyCard.vue';
import { Head } from '@inertiajs/vue3';
import { CalendarDays, ClipboardList, FileWarning, ShieldCheck } from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import type { DashboardColaboradorProps } from '@/types';

defineProps<DashboardColaboradorProps>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-6 p-4">
        <CelebracionesHoyCard />

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <MetricCard
                titulo="Documentos pendientes"
                :valor="misDocumentosPendientes.length"
                :icono="FileWarning"
                tono="warning"
            />
            <MetricCard
                titulo="Vacaciones disponibles"
                :valor="misVacaciones.dias_disponibles"
                :icono="CalendarDays"
            />
            <MetricCard
                titulo="Mis solicitudes pendientes"
                :valor="misSolicitudes.pendientes"
                :icono="ClipboardList"
            />
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card class="rounded-2xl border-border/60">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <FileWarning class="size-4" />
                        Mis documentos pendientes
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="!misDocumentosPendientes.length"
                        class="text-sm text-muted-foreground"
                    >
                        No tienes documentos pendientes. 🎉
                    </p>
                    <div
                        v-for="doc in misDocumentosPendientes"
                        :key="doc.id"
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <span class="truncate">{{ doc.tipo }}</span>
                        <EstadoBadge :estado="doc.status" />
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-border/60">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <ShieldCheck class="size-4" />
                        Avisos pendientes
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-sm text-muted-foreground">
                        Los avisos de privacidad y consentimientos estarán
                        disponibles próximamente.
                    </p>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
