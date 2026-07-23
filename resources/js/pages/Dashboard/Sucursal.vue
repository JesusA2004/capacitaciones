<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import DashboardRhContenido from '@/components/Dashboard/DashboardRhContenido.vue';
import Heading from '@/components/Heading.vue';
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
        <Heading
            title="Portal RH"
            description="Colaboradores, expedientes y documentos dentro de tu alcance (tu sucursal o tu equipo)."
        />

        <DashboardRhContenido
            :cards="cards"
            :graficas="graficas"
            :proximos-aniversarios="proximosAniversarios"
            :documentos-pendientes-revision="documentosPendientesRevision"
            :alertas="alertas"
        />
    </div>
</template>
