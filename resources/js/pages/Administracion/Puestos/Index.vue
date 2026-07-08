<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Briefcase, CheckCircle2, Plus, Users, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import PuestoFormDialog from '@/components/Administracion/PuestoFormDialog.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/administracion/puestos';
import type {
    EstadisticasActivoInactivo,
    OpcionSimple,
    PuestoItem,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    puestos: RespuestaPaginada<PuestoItem>;
    filtros: { busqueda?: string };
    departamentosDisponibles: OpcionSimple[];
    estadisticas: EstadisticasActivoInactivo;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Puestos', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Puesto' },
    { clave: 'departamento', etiqueta: 'Departamento' },
    { clave: 'usuarios_count', etiqueta: 'Colaboradores' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const seleccionado = ref<PuestoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(puesto: PuestoItem) {
    seleccionado.value = puesto;
    dialogAbierto.value = true;
}

async function eliminar(puesto: PuestoItem) {
    const confirmado = await confirmarEliminacion(
        `el puesto «${puesto.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(puesto.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El puesto se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el puesto.'),
    });
}
</script>

<template>
    <Head title="Puestos" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Puestos"
            descripcion="Define los puestos de cada departamento y a qué colaboradores están asignados."
            :icono="Briefcase"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo puesto
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Puestos',
                    valor: estadisticas.total,
                    icono: Briefcase,
                },
                {
                    etiqueta: 'Activos',
                    valor: estadisticas.activos,
                    icono: CheckCircle2,
                    tono: 'success',
                },
                {
                    etiqueta: 'Inactivos',
                    valor: estadisticas.inactivos,
                    icono: XCircle,
                    tono: 'danger',
                },
            ]"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre..."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @limpiar="limpiar"
        />

        <DataTable
            :columnas="columnas"
            :datos="puestos"
            mensaje-vacio="No se encontraron puestos."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Briefcase"
                    titulo="Todavía no hay puestos"
                    descripcion="Crea el primer puesto para poder asignarlo a tus colaboradores."
                >
                    <Button size="sm" @click="abrirCrear">
                        <Plus class="size-4" />
                        Crear puesto
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-departamento="{ fila }">
                {{ fila.departamento?.nombre ?? 'Sin departamento' }}
            </template>
            <template #celda-usuarios_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                >
                    <Users class="size-3.5" />
                    {{ fila.usuarios_count }}
                </span>
            </template>
            <template #celda-activo="{ fila }">
                <EstadoBadge :estado="fila.activo ? 'activo' : 'inactivo'" />
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirEditar(fila)"
                        >Editar</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        variant="destructive"
                        @select="eliminar(fila)"
                        >Eliminar</DropdownMenuItem
                    >
                </CrudActionMenu>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="fila.nombre"
                    :subtitulo="fila.departamento?.nombre ?? 'Sin departamento'"
                >
                    <template #badge>
                        <EstadoBadge
                            :estado="fila.activo ? 'activo' : 'inactivo'"
                        />
                    </template>
                    <span class="inline-flex items-center gap-1.5">
                        <Users class="size-3.5" />
                        {{ fila.usuarios_count }} colaborador(es)
                    </span>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditar(fila)"
                                >Editar</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                variant="destructive"
                                @select="eliminar(fila)"
                                >Eliminar</DropdownMenuItem
                            >
                        </CrudActionMenu>
                    </template>
                </CrudMobileCard>
            </template>
        </DataTable>
    </div>

    <PuestoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :puesto="seleccionado"
        :departamentos-disponibles="departamentosDisponibles"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
