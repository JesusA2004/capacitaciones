<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DashboardRhContenido from '@/components/Dashboard/DashboardRhContenido.vue';
import { dashboard } from '@/routes';
import type { DashboardRhProps } from '@/types';

defineProps<DashboardRhProps>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="flex flex-col gap-6 p-4">
        <DashboardRhContenido
            :cards="cards"
            :graficas="graficas"
            :proximos-aniversarios="proximosAniversarios"
            :documentos-pendientes-revision="documentosPendientesRevision"
            :alertas="alertas"
            :rotacion="rotacion"
            :sucursales-filtro="sucursalesFiltro"
            :departamentos-filtro="departamentosFiltro"
        />
    </div>
</template>
