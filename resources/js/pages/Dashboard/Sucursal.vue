<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ClipboardCheck } from '@lucide/vue';
import BranchComplianceChart from '@/components/Dashboard/BranchComplianceChart.vue';
import DashboardHero from '@/components/Dashboard/DashboardHero.vue';
import GraficasOrganizacion from '@/components/Dashboard/GraficasOrganizacion.vue';
import ProximasSesionesCard from '@/components/Dashboard/ProximasSesionesCard.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import type {
    CumplimientoSucursalItem,
    GraficasOrganizacion as GraficasOrganizacionType,
    ResumenCumplimiento,
    SesionProximaItem,
} from '@/types';

defineProps<{
    resumen: ResumenCumplimiento;
    cumplimientoPorSucursal: CumplimientoSucursalItem[];
    pendientesCalificar: number;
    proximasSesiones: SesionProximaItem[];
    graficas: GraficasOrganizacionType;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-8 p-4">
        <Heading
            title="Inicio"
            description="Resumen de capacitación de tu sucursal."
        />

        <DashboardHero :resumen="resumen" />

        <div
            v-if="pendientesCalificar > 0"
            class="flex items-center gap-3 rounded-2xl border border-warning/30 bg-warning/10 p-4 text-sm shadow-sm"
        >
            <ClipboardCheck class="size-5 shrink-0 text-warning" />
            Tienes
            <strong class="tabular-nums">{{ pendientesCalificar }}</strong>
            entrega(s) pendiente(s) de calificar.
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <BranchComplianceChart :filas="cumplimientoPorSucursal" />
            <ProximasSesionesCard :sesiones="proximasSesiones" />
        </div>

        <GraficasOrganizacion :graficas="graficas" />
    </div>
</template>
