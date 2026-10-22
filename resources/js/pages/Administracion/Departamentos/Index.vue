<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Building2, CheckCircle2, Plus, Users, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import DepartamentoFormDialog from '@/components/Administracion/DepartamentoFormDialog.vue';
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
import { destroy, index, show } from '@/routes/administracion/departamentos';
import type {
    DepartamentoItem,
    EstadisticasActivoInactivo,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    /** Acciones del catálogo por permiso (el backend lo revalida). */
    puede?: { crear: boolean; editar: boolean; eliminar: boolean };
    departamentos: RespuestaPaginada<DepartamentoItem>;
    filtros: { busqueda?: string };
    estadisticas: EstadisticasActivoInactivo;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Departamentos', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Departamento' },
    { clave: 'puestos_count', etiqueta: 'Puestos' },
    { clave: 'colaboradores_count', etiqueta: 'Colaboradores' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const seleccionado = ref<DepartamentoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(departamento: DepartamentoItem) {
    seleccionado.value = departamento;
    dialogAbierto.value = true;
}

/** Abrir un departamento = ver a su gente (y moverla en lote si eres RH). */
function abrirDetalle(departamento: DepartamentoItem) {
    router.visit(show.url(departamento.id));
}

async function eliminar(departamento: DepartamentoItem) {
    const confirmado = await confirmarEliminacion(
        `el departamento «${departamento.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(departamento.id), {
        preserveScroll: true,
        onSuccess: () =>
            mostrarExito('El departamento se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el departamento.'),
    });
}
</script>

<template>
    <Head title="Departamentos" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Departamentos"
            descripcion="Organiza a los colaboradores por área de la empresa y agrupa sus puestos. Abre un departamento para ver a su gente o moverla a otro."
            :icono="Building2"
        >
            <Button v-if="puede?.crear ?? true" @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo departamento
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Departamentos',
                    valor: estadisticas.total,
                    icono: Building2,
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
            :datos="departamentos"
            mensaje-vacio="No se encontraron departamentos."
            filas-clicables
            @click-fila="abrirDetalle"
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Building2"
                    titulo="Todavía no hay departamentos"
                    descripcion="Crea el primer departamento para empezar a organizar a tus colaboradores."
                >
                    <Button
                        v-if="puede?.crear ?? true"
                        size="sm"
                        @click="abrirCrear"
                    >
                        <Plus class="size-4" />
                        Crear departamento
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-colaboradores_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                >
                    <Users class="size-3.5" />
                    {{ fila.colaboradores_count }}
                </span>
            </template>
            <template #celda-activo="{ fila }">
                <EstadoBadge :estado="fila.activo ? 'activo' : 'inactivo'" />
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirDetalle(fila)"
                        >Ver y mover personas</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        v-if="puede?.editar ?? true"
                        @select="abrirEditar(fila)"
                        >Editar</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        v-if="puede?.eliminar ?? true"
                        variant="destructive"
                        @select="eliminar(fila)"
                        >Eliminar</DropdownMenuItem
                    >
                </CrudActionMenu>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="fila.nombre"
                    :subtitulo="`${fila.puestos_count} puesto(s)`"
                    @click="abrirDetalle(fila)"
                >
                    <template #badge>
                        <EstadoBadge
                            :estado="fila.activo ? 'activo' : 'inactivo'"
                        />
                    </template>
                    <span class="inline-flex items-center gap-1.5">
                        <Users class="size-3.5" />
                        {{ fila.colaboradores_count }} colaborador(es)
                    </span>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirDetalle(fila)"
                                >Ver y mover personas</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="puede?.editar ?? true"
                                @select="abrirEditar(fila)"
                                >Editar</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="puede?.eliminar ?? true"
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

    <DepartamentoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :departamento="seleccionado"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
