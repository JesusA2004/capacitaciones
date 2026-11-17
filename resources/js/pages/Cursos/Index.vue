<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    BookOpen,
    GraduationCap,
    Layers,
    Plus,
    Presentation,
    Send,
    Trash2,
    Users,
} from '@lucide/vue';
import { ref } from 'vue';
import PortadaCurso from '@/components/Capacitacion/PortadaCurso.vue';
import CursoFormDialog from '@/components/Cursos/CursoFormDialog.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import { create as nuevaAsignacion } from '@/routes/asignaciones';
import { index as indexCapacitacion } from '@/routes/capacitacion';
import { aula, destroy, edit, index } from '@/routes/cursos';
import type { CursoItem, EstadoCursoOpcion, RespuestaPaginada } from '@/types';

const props = defineProps<{
    cursos: RespuestaPaginada<CursoItem>;
    filtros: { busqueda?: string; estado?: string };
    estados: EstadoCursoOpcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Capacitación', href: indexCapacitacion() },
            { title: 'Cursos y aulas', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, aplicar, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        estado: props.filtros.estado ?? '',
    },
);
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();
const { tienePermiso } = usePermisos();

const columnas: ColumnaDataTable[] = [
    { clave: 'titulo', etiqueta: 'Curso' },
    { clave: 'modulos_count', etiqueta: 'Módulos' },
    { clave: 'inscripciones_count', etiqueta: 'Inscritos' },
    { clave: 'estado', etiqueta: 'Estado' },
    { clave: 'responsable', etiqueta: 'Responsable' },
];

const dialogAbierto = ref(false);

const CLASE_ESTADO: Record<string, string> = {
    publicado: 'bg-success-soft text-primary',
    borrador: 'bg-warning-soft text-bronce',
    archivado: 'bg-muted text-muted-foreground',
};

function filtrarEstado(valor: string) {
    filtros.estado = filtros.estado === valor ? '' : valor;
    aplicar();
}

async function eliminar(curso: CursoItem) {
    const confirmado = await confirmarEliminacion(`el curso «${curso.titulo}»`);

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(curso.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El curso se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el curso.'),
    });
}
</script>

<template>
    <Head title="Cursos y aulas" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Cursos y aulas"
            descripcion="Crea el contenido de cada curso y abre su aula para ver avance, trabajo y calificaciones."
            :icono="GraduationCap"
        >
            <Button
                v-if="tienePermiso('asignaciones.crear')"
                variant="outline"
                as-child
            >
                <Link :href="nuevaAsignacion()">
                    <Send class="size-4" />
                    Asignar curso
                </Link>
            </Button>
            <Button
                v-if="tienePermiso('cursos.crear')"
                @click="dialogAbierto = true"
            >
                <Plus class="size-4" />
                Nuevo curso
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <TableFilters
                :model-value="filtros.busqueda"
                placeholder="Buscar por título…"
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                @limpiar="limpiar"
            >
                <div
                    class="flex flex-wrap gap-1 rounded-xl border border-border/60 bg-crema/40 p-1"
                    role="group"
                    aria-label="Filtrar por estado"
                >
                    <button
                        type="button"
                        class="presionable rounded-lg px-3 py-1.5 text-xs font-medium transicion-ui"
                        :class="
                            filtros.estado === ''
                                ? 'bg-card text-primary shadow-sm'
                                : 'text-muted-foreground hover:bg-card/60'
                        "
                        :aria-pressed="filtros.estado === ''"
                        @click="filtrarEstado('')"
                    >
                        Todos
                    </button>
                    <button
                        v-for="opcion in estados"
                        :key="opcion.value"
                        type="button"
                        class="presionable rounded-lg px-3 py-1.5 text-xs font-medium transicion-ui"
                        :class="
                            filtros.estado === opcion.value
                                ? 'bg-card text-primary shadow-sm'
                                : 'text-muted-foreground hover:bg-card/60'
                        "
                        :aria-pressed="filtros.estado === opcion.value"
                        @click="filtrarEstado(opcion.value)"
                    >
                        {{ opcion.etiqueta }}
                    </button>
                </div>
            </TableFilters>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="cursos"
            mensaje-vacio="No se encontraron cursos. Crea uno nuevo o cambia los filtros."
        >
            <template #celda-titulo="{ fila }">
                <Link
                    :href="aula(fila.id)"
                    class="group flex min-w-0 items-center gap-3"
                >
                    <PortadaCurso
                        :curso-id="fila.id"
                        alto="size-11"
                        class="shrink-0 rounded-xl [&_svg]:size-5"
                    />
                    <span class="min-w-0">
                        <span
                            class="block truncate font-medium group-hover:text-primary group-hover:underline"
                            >{{ fila.titulo }}</span
                        >
                        <span
                            v-if="fila.descripcion || fila.objetivo"
                            class="line-clamp-1 text-xs text-muted-foreground"
                            >{{ fila.descripcion || fila.objetivo }}</span
                        >
                    </span>
                </Link>
            </template>
            <template #celda-modulos_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground tabular-nums"
                >
                    <Layers class="size-3.5" aria-hidden="true" />
                    {{ fila.modulos_count ?? 0 }}
                </span>
            </template>
            <template #celda-inscripciones_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground tabular-nums"
                >
                    <Users class="size-3.5" aria-hidden="true" />
                    {{ fila.inscripciones_count ?? 0 }}
                </span>
            </template>
            <template #celda-estado="{ fila }">
                <Badge
                    variant="secondary"
                    :class="
                        CLASE_ESTADO[fila.estado] ??
                        'bg-muted text-muted-foreground'
                    "
                    >{{
                        estados.find((e) => e.value === fila.estado)
                            ?.etiqueta ?? fila.estado
                    }}</Badge
                >
            </template>
            <template #celda-responsable="{ fila }">
                <span :class="{ 'text-muted-foreground': !fila.responsable }">{{
                    fila.responsable
                        ? `${fila.responsable.name} ${fila.responsable.apellidos ?? ''}`
                        : 'Sin asignar'
                }}</span>
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Abrir aula (tablón, trabajo, calificaciones y participantes)"
                        aria-label="Abrir aula"
                        as-child
                    >
                        <Link :href="aula(fila.id)">
                            <Presentation class="size-4" />
                        </Link>
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Abrir constructor (módulos y lecciones)"
                        aria-label="Abrir constructor"
                        as-child
                    >
                        <Link :href="edit(fila.id)">
                            <BookOpen class="size-4" />
                        </Link>
                    </Button>
                    <Button
                        v-if="tienePermiso('cursos.eliminar')"
                        variant="ghost"
                        size="icon"
                        title="Eliminar"
                        aria-label="Eliminar curso"
                        @click="eliminar(fila)"
                    >
                        <Trash2 class="size-4 text-destructive" />
                    </Button>
                </div>
            </template>
        </DataTable>
    </div>

    <CursoFormDialog v-if="dialogAbierto" v-model:open="dialogAbierto" />
</template>
