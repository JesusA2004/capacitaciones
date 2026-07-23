<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarDays,
    ClipboardList,
    FileWarning,
    FolderOpen,
    GraduationCap,
    ShieldCheck,
} from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import Heading from '@/components/Heading.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { dashboard, miExpediente as rutaMiExpediente } from '@/routes';
import { proximamente } from '@/routes/capacitacion';
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
        <Heading
            title="Inicio"
            description="Tu expediente, documentos y solicitudes en un solo lugar."
        />

        <Card class="rounded-3xl border-border/60">
            <CardHeader class="flex-row items-center justify-between">
                <CardTitle class="flex items-center gap-2 text-base">
                    <FolderOpen class="size-4" />
                    Mi expediente
                </CardTitle>
                <Link
                    :href="rutaMiExpediente()"
                    class="text-xs font-medium text-primary hover:underline"
                    >Ver expediente completo →</Link
                >
            </CardHeader>
            <CardContent class="flex flex-col gap-3">
                <div class="flex items-center gap-3">
                    <Progress
                        :model-value="miExpediente.porcentaje"
                        class="h-2"
                    />
                    <span class="shrink-0 text-sm font-semibold tabular-nums"
                        >{{ miExpediente.porcentaje }}%</span
                    >
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ miExpediente.pendientes }} documento(s) pendiente(s) o
                    por corregir.
                </p>
            </CardContent>
        </Card>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <MetricCard
                titulo="Documentos pendientes"
                :valor="misDocumentosPendientes.length"
                :icono="FileWarning"
                tono="warning"
            />
            <MetricCard
                titulo="Vacaciones disponibles"
                valor="—"
                subvalor="Próximamente"
                :icono="CalendarDays"
            />
            <MetricCard
                titulo="Mis solicitudes"
                valor="—"
                subvalor="Próximamente"
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

        <Link
            :href="proximamente()"
            class="flex items-center gap-3 rounded-2xl border border-border/60 bg-card p-4 text-sm shadow-sm transition-colors hover:border-primary/40"
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]"
            >
                <GraduationCap class="size-4" />
            </span>
            <span>
                <span class="font-medium">Capacitación</span>
                <span class="ml-2 text-xs text-muted-foreground"
                    >Próximamente</span
                >
            </span>
        </Link>
    </div>
</template>
