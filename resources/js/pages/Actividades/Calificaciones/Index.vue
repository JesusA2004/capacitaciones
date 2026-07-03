<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/calificaciones/actividades';
import type { EntregaCalificacionItem, RespuestaPaginada } from '@/types';

defineProps<{
    entregas: RespuestaPaginada<EntregaCalificacionItem>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Calificar actividades', href: index.url() },
        ],
    },
});

const columnas: ColumnaDataTable[] = [
    { clave: 'actividad', etiqueta: 'Actividad' },
    { clave: 'usuario', etiqueta: 'Colaborador' },
    { clave: 'entregado_en', etiqueta: 'Entregado' },
];
</script>

<template>
    <Head title="Calificar actividades" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Calificar actividades"
            description="Entregas pendientes de revisión"
        />

        <DataTable
            :columnas="columnas"
            :datos="entregas"
            mensaje-vacio="No hay entregas pendientes de calificación."
        >
            <template #celda-actividad="{ fila }">
                <Link :href="show.url(fila.id)" class="font-medium underline">
                    {{ fila.actividad.titulo }}
                </Link>
            </template>
            <template #celda-usuario="{ fila }">
                {{ fila.usuario.name }} {{ fila.usuario.apellidos ?? '' }}
            </template>
        </DataTable>
    </div>
</template>
