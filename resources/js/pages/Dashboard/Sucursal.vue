<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CumplimientoSucursalTable from '@/components/Dashboard/CumplimientoSucursalTable.vue';
import ProximasSesionesCard from '@/components/Dashboard/ProximasSesionesCard.vue';
import ResumenCumplimientoCards from '@/components/Dashboard/ResumenCumplimientoCards.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import type {
    CumplimientoSucursalItem,
    ResumenCumplimiento,
    SesionProximaItem,
} from '@/types';

defineProps<{
    resumen: ResumenCumplimiento;
    cumplimientoPorSucursal: CumplimientoSucursalItem[];
    pendientesCalificar: number;
    proximasSesiones: SesionProximaItem[];
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
        <Heading
            title="Inicio"
            description="Resumen de capacitación de tu sucursal"
        />

        <ResumenCumplimientoCards :resumen="resumen" />

        <div
            v-if="pendientesCalificar > 0"
            class="rounded-xl border p-4 text-sm"
        >
            Tienes <strong>{{ pendientesCalificar }}</strong> entrega(s)
            pendiente(s) de calificar.
        </div>

        <div class="grid gap-4 lg:grid-cols-2">
            <CumplimientoSucursalTable :filas="cumplimientoPorSucursal" />
            <ProximasSesionesCard :sesiones="proximasSesiones" />
        </div>
    </div>
</template>
