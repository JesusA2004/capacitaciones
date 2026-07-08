<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BranchComplianceChart from '@/components/Dashboard/BranchComplianceChart.vue';
import DashboardHero from '@/components/Dashboard/DashboardHero.vue';
import GraficasOrganizacion from '@/components/Dashboard/GraficasOrganizacion.vue';
import { dashboard } from '@/routes';
import type {
    CumplimientoSucursalItem,
    GraficasOrganizacion as GraficasOrganizacionType,
    ResumenCumplimiento,
} from '@/types';

defineProps<{
    resumen: ResumenCumplimiento;
    cumplimientoPorSucursal: CumplimientoSucursalItem[];
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

    <div class="flex flex-col gap-0 p-4">
        <DashboardHero :resumen="resumen" />

        <BranchComplianceChart :filas="cumplimientoPorSucursal" />

        <GraficasOrganizacion :graficas="graficas" />
    </div>
</template>
