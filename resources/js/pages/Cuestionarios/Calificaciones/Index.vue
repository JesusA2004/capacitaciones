<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/calificaciones/cuestionarios';
import type { IntentoCalificacionItem, RespuestaPaginada } from '@/types';

defineProps<{
    intentos: RespuestaPaginada<IntentoCalificacionItem>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Calificar cuestionarios', href: index.url() },
        ],
    },
});

const columnas: ColumnaDataTable[] = [
    { clave: 'cuestionario', etiqueta: 'Cuestionario' },
    { clave: 'usuario', etiqueta: 'Colaborador' },
    { clave: 'enviado_en', etiqueta: 'Enviado' },
];
</script>

<template>
    <Head title="Calificar cuestionarios" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Calificar cuestionarios"
            description="Intentos con preguntas de respuesta corta pendientes de revisión"
        />

        <DataTable
            :columnas="columnas"
            :datos="intentos"
            mensaje-vacio="No hay intentos pendientes de calificación."
        >
            <template #celda-cuestionario="{ fila }">
                <Link :href="show.url(fila.id)" class="font-medium underline">
                    {{ fila.cuestionario.titulo }}
                </Link>
            </template>
            <template #celda-usuario="{ fila }">
                {{ fila.usuario.name }} {{ fila.usuario.apellidos ?? '' }}
            </template>
        </DataTable>
    </div>
</template>
