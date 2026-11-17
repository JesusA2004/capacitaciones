<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Ban,
    CalendarClock,
    Plus,
    Presentation,
    Send,
    Users,
    X,
} from '@lucide/vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { usePermisos } from '@/composables/usePermisos';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { cancelar, create, index } from '@/routes/asignaciones';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula } from '@/routes/cursos';
import type { AsignacionItem, RespuestaPaginada } from '@/types';

const props = defineProps<{
    asignaciones: RespuestaPaginada<AsignacionItem>;
    cursoFiltro: { id: number; titulo: string } | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Asignaciones', href: index.url() },
        ],
    },
});

const { tienePermiso } = usePermisos();
const { mostrarExito, mostrarError, confirmarDelicada } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Asignación' },
    { clave: 'asignable', etiqueta: 'Curso' },
    { clave: 'asignaciones_usuario_count', etiqueta: 'Colaboradores' },
    { clave: 'fecha_limite', etiqueta: 'Fecha límite' },
    { clave: 'activa', etiqueta: 'Estado' },
];

/** Días que faltan para la fecha límite (negativo = ya venció). */
function diasRestantes(fecha: string): number {
    const hoy = new Date();
    hoy.setHours(0, 0, 0, 0);
    const limite = new Date(fecha);
    limite.setHours(0, 0, 0, 0);

    return Math.round((limite.getTime() - hoy.getTime()) / 86_400_000);
}

async function cancelarAsignacion(asignacion: AsignacionItem) {
    const confirmado = await confirmarDelicada(
        '¿Cancelar la asignación?',
        `«${asignacion.nombre}» deja de estar vigente: lo que cada persona no haya completado queda cancelado; lo ya completado se conserva.`,
        'Sí, cancelar',
    );

    if (!confirmado) {
        return;
    }

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

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Asignaciones"
            descripcion="Asigna cursos a colaboradores, sucursales, departamentos, puestos o roles."
            :icono="Send"
        >
            <Button v-if="tienePermiso('asignaciones.crear')" as-child>
                <Link
                    :href="
                        create(
                            cursoFiltro
                                ? { query: { curso: cursoFiltro.id } }
                                : {},
                        )
                    "
                >
                    <Plus class="size-4" />
                    Nueva asignación
                </Link>
            </Button>
        </CrudPageHeader>

        <div
            v-if="props.cursoFiltro"
            class="flex entrada-suave flex-wrap items-center gap-2 rounded-2xl border border-border/60 bg-salvia/50 px-4 py-3 text-sm"
        >
            <span class="text-muted-foreground">Solo del curso</span>
            <Link
                :href="aula(props.cursoFiltro.id)"
                class="inline-flex items-center gap-1.5 font-semibold text-primary hover:underline"
            >
                <Presentation class="size-4" />
                {{ props.cursoFiltro.titulo }}
            </Link>
            <Button variant="ghost" size="sm" class="ml-auto" as-child>
                <Link :href="index()">
                    <X class="size-4" />
                    Ver todas
                </Link>
            </Button>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="asignaciones"
            :mensaje-vacio="
                cursoFiltro
                    ? 'Este curso todavía no se ha asignado a nadie.'
                    : 'Todavía no hay asignaciones. Crea la primera para inscribir a la gente en un curso.'
            "
        >
            <template #celda-nombre="{ fila }">
                <span class="font-medium">{{ fila.nombre }}</span>
                <span
                    v-if="!fila.obligatoria"
                    class="ml-2 text-xs text-muted-foreground"
                    >opcional</span
                >
            </template>
            <template #celda-asignable="{ fila }">
                <Link
                    v-if="fila.asignable"
                    :href="aula(fila.asignable.id)"
                    class="enlace-animado text-primary"
                    >{{ fila.asignable.titulo }}</Link
                >
                <span v-else class="text-muted-foreground">—</span>
            </template>
            <template #celda-asignaciones_usuario_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground tabular-nums"
                >
                    <Users class="size-3.5" aria-hidden="true" />
                    {{ fila.asignaciones_usuario_count }}
                </span>
            </template>
            <template #celda-fecha_limite="{ fila }">
                <span
                    v-if="fila.fecha_limite"
                    class="inline-flex items-center gap-1.5"
                    :class="
                        fila.activa && diasRestantes(fila.fecha_limite) < 0
                            ? 'text-destructive'
                            : fila.activa &&
                                diasRestantes(fila.fecha_limite) <= 7
                              ? 'text-bronce'
                              : ''
                    "
                >
                    <CalendarClock class="size-3.5" aria-hidden="true" />
                    {{ formatearFecha(fila.fecha_limite) }}
                    <span
                        v-if="
                            fila.activa &&
                            diasRestantes(fila.fecha_limite) >= 0 &&
                            diasRestantes(fila.fecha_limite) <= 7
                        "
                        class="text-xs"
                        >· en
                        {{ diasRestantes(fila.fecha_limite) }} día(s)</span
                    >
                    <span
                        v-else-if="
                            fila.activa && diasRestantes(fila.fecha_limite) < 0
                        "
                        class="text-xs"
                        >· vencida</span
                    >
                </span>
                <span v-else class="text-muted-foreground"
                    >Sin fecha límite</span
                >
            </template>
            <template #celda-activa="{ fila }">
                <Badge
                    variant="secondary"
                    :class="
                        fila.activa
                            ? 'bg-success-soft text-primary'
                            : 'bg-muted text-muted-foreground'
                    "
                    >{{ fila.activa ? 'Activa' : 'Cancelada' }}</Badge
                >
            </template>
            <template #acciones="{ fila }">
                <Button
                    v-if="fila.activa && tienePermiso('asignaciones.cancelar')"
                    variant="ghost"
                    size="icon"
                    title="Cancelar asignación"
                    aria-label="Cancelar asignación"
                    @click="cancelarAsignacion(fila)"
                >
                    <Ban class="size-4 text-destructive" />
                </Button>
            </template>
        </DataTable>
    </div>
</template>
