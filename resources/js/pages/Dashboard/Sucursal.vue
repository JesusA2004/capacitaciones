<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CelebracionesHoyCard from '@/components/Celebraciones/CelebracionesHoyCard.vue';
import DashboardRhContenido from '@/components/Dashboard/DashboardRhContenido.vue';
import TableroRh from '@/components/Dashboard/TableroRh.vue';
import { dashboard } from '@/routes';
import type { DashboardRhProps, TableroRhDatos } from '@/types';

defineProps<DashboardRhProps & { tablero?: TableroRhDatos }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Inicio', href: dashboard() }],
    },
});
</script>

<template>
    <Head title="Inicio" />

    <div class="pagina-ancha flex flex-col gap-6">
        <TableroRh v-if="tablero" :tablero="tablero" />
        <CelebracionesHoyCard />
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
