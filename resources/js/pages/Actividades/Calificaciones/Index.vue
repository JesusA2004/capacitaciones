<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, ClipboardCheck } from '@lucide/vue';
import PestanasCalificar from '@/components/Capacitacion/PestanasCalificar.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/calificaciones/actividades';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula } from '@/routes/cursos';
import type { EntregaCalificacionItem, RespuestaPaginada } from '@/types';

defineProps<{
    entregas: RespuestaPaginada<EntregaCalificacionItem>;
    cursoFiltro: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Calificar actividades', href: index.url() },
        ],
    },
});

const columnas: ColumnaDataTable[] = [
    { clave: 'usuario', etiqueta: 'Colaborador' },
    { clave: 'actividad', etiqueta: 'Actividad' },
    { clave: 'curso', etiqueta: 'Curso' },
    { clave: 'entregado_en', etiqueta: 'Entregado' },
];
</script>

<template>
    <Head title="Calificar actividades" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Calificar actividades"
            descripcion="Entregas pendientes de revisión, de la más antigua a la más reciente."
            :icono="ClipboardCheck"
        />

        <PestanasCalificar
            actual="actividades"
            :total="entregas.total"
            :curso="cursoFiltro"
        />

        <DataTable
            :columnas="columnas"
            :datos="entregas"
            mensaje-vacio="Todo al día: no hay entregas esperando calificación."
        >
            <template #celda-usuario="{ fila }">
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-menta text-xs font-semibold text-esmeralda"
                        aria-hidden="true"
                        >{{
                            getInitials(
                                `${fila.usuario.name} ${fila.usuario.apellidos ?? ''}`,
                            )
                        }}</span
                    >
                    <span class="truncate font-medium"
                        >{{ fila.usuario.name }}
                        {{ fila.usuario.apellidos ?? '' }}</span
                    >
                </div>
            </template>
            <template #celda-actividad="{ fila }">
                <Link
                    :href="show.url(fila.id)"
                    class="enlace-animado font-medium text-primary"
                >
                    {{ fila.actividad.titulo }}
                </Link>
                <span class="ml-1 text-xs text-muted-foreground"
                    >· entrega {{ fila.version }}</span
                >
            </template>
            <template #celda-curso="{ fila }">
                <Link
                    v-if="fila.curso"
                    :href="aula(fila.curso.id)"
                    class="text-muted-foreground hover:text-primary hover:underline"
                    >{{ fila.curso.titulo }}</Link
                >
                <span v-else class="text-muted-foreground">—</span>
            </template>
            <template #celda-entregado_en="{ fila }">
                <span class="text-muted-foreground tabular-nums">{{
                    formatearFechaHora(fila.entregado_en)
                }}</span>
            </template>
            <template #acciones="{ fila }">
                <Button size="sm" variant="secondary" as-child>
                    <Link :href="show.url(fila.id)">
                        Calificar
                        <ChevronRight class="size-4" />
                    </Link>
                </Button>
            </template>
        </DataTable>
    </div>
</template>
