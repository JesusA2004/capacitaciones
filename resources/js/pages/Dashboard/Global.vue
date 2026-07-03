<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CumplimientoSucursalTable from '@/components/Dashboard/CumplimientoSucursalTable.vue';
import ResumenCumplimientoCards from '@/components/Dashboard/ResumenCumplimientoCards.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import type { CumplimientoSucursalItem, ResumenCumplimiento } from '@/types';

defineProps<{
    resumen: ResumenCumplimiento;
    cumplimientoPorSucursal: CumplimientoSucursalItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-6 p-4">
        <Heading title="Inicio" description="Resumen global de capacitación" />

        <ResumenCumplimientoCards :resumen="resumen" />

        <CumplimientoSucursalTable :filas="cumplimientoPorSucursal" />
    </div>
</template>
