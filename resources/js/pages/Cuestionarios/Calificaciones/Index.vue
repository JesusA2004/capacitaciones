<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, HelpCircle } from '@lucide/vue';
import PestanasCalificar from '@/components/Capacitacion/PestanasCalificar.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import { Button } from '@/components/ui/button';
import { getInitials } from '@/composables/useInitials';
import { formatearFechaHora } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index, show } from '@/routes/calificaciones/cuestionarios';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula } from '@/routes/cursos';
import type { IntentoCalificacionItem, RespuestaPaginada } from '@/types';

defineProps<{
    intentos: RespuestaPaginada<IntentoCalificacionItem>;
    cursoFiltro: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Calificar cuestionarios', href: index.url() },
        ],
    },
});

const columnas: ColumnaDataTable[] = [
    { clave: 'usuario', etiqueta: 'Colaborador' },
    { clave: 'cuestionario', etiqueta: 'Cuestionario' },
    { clave: 'curso', etiqueta: 'Curso' },
    { clave: 'enviado_en', etiqueta: 'Enviado' },
];
</script>

<template>
    <Head title="Calificar cuestionarios" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Calificar cuestionarios"
            descripcion="Intentos con preguntas abiertas pendientes de revisión, del más antiguo al más reciente."
            :icono="HelpCircle"
        />

        <PestanasCalificar
            actual="cuestionarios"
            :total="intentos.total"
            :curso="cursoFiltro"
        />

        <DataTable
            :columnas="columnas"
            :datos="intentos"
            mensaje-vacio="Todo al día: no hay cuestionarios esperando calificación."
        >
            <template #celda-usuario="{ fila }">
                <div class="flex min-w-0 items-center gap-3">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-full bg-salvia text-xs font-semibold text-primary"
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
            <template #celda-cuestionario="{ fila }">
                <Link
                    :href="show.url(fila.id)"
                    class="enlace-animado font-medium text-primary"
                >
                    {{ fila.cuestionario.titulo }}
                </Link>
                <span class="ml-1 text-xs text-muted-foreground"
                    >· intento {{ fila.numero_intento }}</span
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
            <template #celda-enviado_en="{ fila }">
                <span class="text-muted-foreground tabular-nums">{{
                    formatearFechaHora(fila.enviado_en)
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
