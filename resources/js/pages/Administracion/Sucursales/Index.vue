<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Building,
    CheckCircle2,
    MapPin,
    Plus,
    Users,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import SucursalFormDialog from '@/components/Administracion/SucursalFormDialog.vue';
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
import { destroy, index } from '@/routes/administracion/sucursales';
import type {
    EstadisticasActivoInactivo,
    RespuestaPaginada,
    SucursalItem,
} from '@/types';

const props = defineProps<{
    sucursales: RespuestaPaginada<SucursalItem>;
    filtros: { busqueda?: string };
    responsablesDisponibles: {
        id: number;
        name: string;
        apellidos: string | null;
    }[];
    estadisticas: EstadisticasActivoInactivo;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Sucursales', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Sucursal' },
    { clave: 'clave', etiqueta: 'Clave' },
    { clave: 'ciudad', etiqueta: 'Ciudad' },
    { clave: 'usuarios_count', etiqueta: 'Colaboradores' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const sucursalSeleccionada = ref<SucursalItem | null>(null);

function abrirCrear() {
    sucursalSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(sucursal: SucursalItem) {
    sucursalSeleccionada.value = sucursal;
    dialogAbierto.value = true;
}

async function eliminar(sucursal: SucursalItem) {
    const confirmado = await confirmarEliminacion(
        `la sucursal «${sucursal.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(sucursal.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La sucursal se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar la sucursal.'),
    });
}
</script>

<template>
    <Head title="Sucursales" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Sucursales"
            descripcion="Organiza la capacitación por ubicación y revisa el alcance de cada responsable."
            :icono="Building"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva sucursal
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Sucursales',
                    valor: estadisticas.total,
                    icono: Building,
                },
                {
                    etiqueta: 'Activas',
                    valor: estadisticas.activos,
                    icono: CheckCircle2,
                    tono: 'success',
                },
                {
                    etiqueta: 'Inactivas',
                    valor: estadisticas.inactivos,
                    icono: XCircle,
                    tono: 'danger',
                },
            ]"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre o clave..."
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
            :datos="sucursales"
            mensaje-vacio="No se encontraron sucursales."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Building"
                    titulo="Todavía no hay sucursales"
                    descripcion="Crea la primera sucursal para empezar a organizar la capacitación por ubicación."
                >
                    <Button size="sm" @click="abrirCrear">
                        <Plus class="size-4" />
                        Crear sucursal
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-ciudad="{ fila }">
                <span
                    v-if="fila.ciudad"
                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                >
                    <MapPin class="size-3.5" />
                    {{ fila.ciudad }}
                </span>
                <span v-else class="text-muted-foreground">—</span>
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
                    :subtitulo="`${fila.clave} · ${fila.ciudad ?? 'Sin ciudad'}`"
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

    <SucursalFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :sucursal="sucursalSeleccionada"
        :responsables-disponibles="responsablesDisponibles"
        :key="sucursalSeleccionada?.id ?? 'nueva'"
    />
</template>
