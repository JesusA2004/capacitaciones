<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, Plus } from '@lucide/vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import { cancelar, create, index } from '@/routes/asignaciones';
import type { AsignacionItem, RespuestaPaginada } from '@/types';

defineProps<{
    asignaciones: RespuestaPaginada<AsignacionItem>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Asignaciones', href: index.url() },
        ],
    },
});

const { tienePermiso } = usePermisos();
const { mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Asignación' },
    { clave: 'asignable', etiqueta: 'Curso' },
    { clave: 'asignaciones_usuario_count', etiqueta: 'Colaboradores' },
    { clave: 'fecha_limite', etiqueta: 'Fecha límite' },
    { clave: 'activa', etiqueta: 'Estado' },
];

function cancelarAsignacion(asignacion: AsignacionItem) {
    router.post(
        cancelar.url(asignacion.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                mostrarExito('La asignación se canceló correctamente.'),
            onError: () =>
                mostrarError('No fue posible cancelar la asignación.'),
        },
    );
}
</script>

<template>
    <Head title="Asignaciones" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-center justify-between">
            <Heading
                title="Asignaciones"
                description="Asigna cursos a colaboradores, sucursales, departamentos, puestos o roles"
            />
            <Button v-if="tienePermiso('asignaciones.crear')" as-child>
                <Link :href="create()">
                    <Plus class="size-4" />
                    Nueva asignación
                </Link>
            </Button>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="asignaciones"
            mensaje-vacio="Todavía no hay asignaciones."
        >
            <template #celda-asignable="{ fila }">
                {{ fila.asignable?.titulo ?? '—' }}
            </template>
            <template #celda-fecha_limite="{ fila }">
                {{
                    fila.fecha_limite
                        ? new Date(fila.fecha_limite).toLocaleDateString(
                              'es-MX',
                          )
                        : 'Sin fecha límite'
                }}
            </template>
            <template #celda-activa="{ fila }">
                <Badge :variant="fila.activa ? 'default' : 'secondary'">{{
                    fila.activa ? 'Activa' : 'Cancelada'
                }}</Badge>
            </template>
            <template #acciones="{ fila }">
                <Button
                    v-if="fila.activa && tienePermiso('asignaciones.cancelar')"
                    variant="ghost"
                    size="icon"
                    title="Cancelar asignación"
                    @click="cancelarAsignacion(fila)"
                >
                    <Ban class="size-4 text-destructive" />
                </Button>
            </template>
        </DataTable>
    </div>
</template>
