<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CakeSlice,
    FileWarning,
    FolderCheck,
    FolderX,
    Info,
    UserMinus,
    UserPlus,
    Users,
} from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import DashboardChartCard from '@/components/Dashboard/DashboardChartCard.vue';
import DashboardSection from '@/components/Dashboard/DashboardSection.vue';
import MetricCard from '@/components/Dashboard/MetricCard.vue';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { proximamente } from '@/routes/capacitacion';
import type { DashboardRhProps } from '@/types';

defineProps<DashboardRhProps>();

const TONO_ALERTA: Record<string, string> = {
    warning: 'border-warning/30 bg-warning/10 text-warning',
    danger: 'border-destructive/30 bg-destructive/10 text-destructive',
    info: 'border-[var(--brand-secondary)]/30 bg-[var(--brand-secondary)]/10 text-[var(--brand-secondary)]',
};
</script>

<template>
    <div class="flex flex-col gap-8">
        <DashboardSection titulo="Resumen" :columnas="4">
            <MetricCard
                titulo="Colaboradores activos"
                :valor="cards.colaboradores_activos"
                :icono="Users"
                tono="success"
            />
            <MetricCard
                titulo="Altas en proceso"
                valor="—"
                subvalor="Próximamente"
                :icono="UserPlus"
            />
            <MetricCard
                titulo="Bajas del mes"
                :valor="cards.bajas_del_mes"
                :icono="UserMinus"
                tono="danger"
            />
            <MetricCard
                titulo="Documentos pendientes"
                :valor="cards.documentos_pendientes"
                :icono="FileWarning"
                tono="warning"
            />
            <MetricCard
                titulo="Expedientes completos"
                :valor="cards.expedientes_completos"
                :icono="FolderCheck"
                tono="success"
            />
            <MetricCard
                titulo="Expedientes incompletos"
                :valor="cards.expedientes_incompletos"
                :icono="FolderX"
                tono="warning"
            />
            <MetricCard
                titulo="Solicitudes RH pendientes"
                valor="—"
                subvalor="Próximamente"
                :icono="AlertTriangle"
            />
            <MetricCard
                titulo="Vacaciones pendientes"
                valor="—"
                subvalor="Próximamente"
                :icono="CakeSlice"
            />
        </DashboardSection>

        <DashboardSection
            titulo="Organización"
            descripcion="Distribución de colaboradores dentro de tu alcance."
            :columnas="4"
        >
            <DashboardChartCard
                title="Por empresa"
                type="bar"
                :data="graficas.colaboradoresPorEmpresa"
                x-key="etiqueta"
                y-key="valor"
                :height="180"
                empty-title="Sin datos"
                empty-description="Todavía no hay colaboradores para mostrar."
            />
            <DashboardChartCard
                title="Por sucursal"
                type="bar"
                :data="graficas.colaboradoresPorSucursal"
                x-key="etiqueta"
                y-key="valor"
                :height="180"
                empty-title="Sin datos"
                empty-description="Todavía no hay colaboradores para mostrar."
            />
            <DashboardChartCard
                title="Por departamento"
                type="bar"
                :data="graficas.colaboradoresPorDepartamento"
                x-key="etiqueta"
                y-key="valor"
                :height="180"
                empty-title="Sin datos"
                empty-description="Todavía no hay colaboradores para mostrar."
            />
            <DashboardChartCard
                title="Por puesto"
                type="bar"
                :data="graficas.colaboradoresPorPuesto"
                x-key="etiqueta"
                y-key="valor"
                :height="180"
                empty-title="Sin datos"
                empty-description="Todavía no hay colaboradores para mostrar."
            />
        </DashboardSection>

        <DashboardSection titulo="Expedientes y documentos" :columnas="2">
            <DashboardChartCard
                title="Expedientes: completos vs. incompletos"
                type="donut"
                :data="graficas.expedientesEstado"
                label-key="etiqueta"
                value-key="valor"
                :height="200"
                empty-title="Sin expedientes"
                empty-description="Todavía no hay colaboradores con expediente."
            />
            <DashboardChartCard
                title="Documentos por estado"
                type="donut"
                :data="graficas.documentosPorEstado"
                label-key="etiqueta"
                value-key="valor"
                :height="200"
                empty-title="Sin documentos"
                empty-description="Todavía no se han cargado documentos."
            />
        </DashboardSection>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card class="rounded-2xl border-border/60 lg:col-span-1">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <CakeSlice class="size-4" />
                        Próximos aniversarios
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="!proximosAniversarios.length"
                        class="text-sm text-muted-foreground"
                    >
                        Sin aniversarios en los próximos 30 días.
                    </p>
                    <div
                        v-for="item in proximosAniversarios"
                        :key="item.id"
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <span class="truncate">{{ item.nombre }}</span>
                        <Badge variant="secondary"
                            >{{ item.anios }} año(s) · {{ item.dias }}d</Badge
                        >
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-border/60 lg:col-span-1">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <FileWarning class="size-4" />
                        Documentos por revisar
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-3">
                    <p
                        v-if="!documentosPendientesRevision.length"
                        class="text-sm text-muted-foreground"
                    >
                        No hay documentos pendientes de revisión.
                    </p>
                    <div
                        v-for="doc in documentosPendientesRevision"
                        :key="doc.id"
                        class="flex items-center justify-between gap-2 text-sm"
                    >
                        <div class="min-w-0">
                            <p class="truncate font-medium">{{ doc.tipo }}</p>
                            <p class="truncate text-xs text-muted-foreground">
                                {{ doc.colaborador }}
                            </p>
                        </div>
                        <EstadoBadge :estado="doc.status" />
                    </div>
                </CardContent>
            </Card>

            <Card class="rounded-2xl border-border/60 lg:col-span-1">
                <CardHeader>
                    <CardTitle class="flex items-center gap-2 text-base">
                        <Info class="size-4" />
                        Alertas RH
                    </CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <p
                        v-if="!alertas.length"
                        class="text-sm text-muted-foreground"
                    >
                        Sin alertas por ahora.
                    </p>
                    <div
                        v-for="(alerta, indice) in alertas"
                        :key="indice"
                        class="rounded-xl border px-3 py-2 text-xs"
                        :class="TONO_ALERTA[alerta.tono]"
                    >
                        {{ alerta.mensaje }}
                    </div>
                </CardContent>
            </Card>
        </div>

        <Link
            :href="proximamente()"
            class="text-xs text-muted-foreground underline-offset-4 hover:underline"
        >
            Capacitación (Próximamente) →
        </Link>
    </div>
</template>
