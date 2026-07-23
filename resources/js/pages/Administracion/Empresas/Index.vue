<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Building2, CheckCircle2, MapPinned, Plus, XCircle } from '@lucide/vue';
import { ref } from 'vue';
import EmpresaFormDialog from '@/components/Administracion/EmpresaFormDialog.vue';
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
import { destroy, index } from '@/routes/administracion/empresas';
import type {
    EmpresaItem,
    EstadisticasActivoInactivo,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    empresas: RespuestaPaginada<EmpresaItem>;
    filtros: { busqueda?: string };
    estadisticas: EstadisticasActivoInactivo;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Empresas', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, limpiar } = useFiltros(index.url(), {
    busqueda: props.filtros.busqueda ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const columnas: ColumnaDataTable[] = [
    { clave: 'nombre', etiqueta: 'Empresa' },
    { clave: 'rfc', etiqueta: 'RFC' },
    { clave: 'sucursales_count', etiqueta: 'Sucursales' },
    { clave: 'activo', etiqueta: 'Estado' },
];

const dialogAbierto = ref(false);
const empresaSeleccionada = ref<EmpresaItem | null>(null);

function abrirCrear() {
    empresaSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(empresa: EmpresaItem) {
    empresaSeleccionada.value = empresa;
    dialogAbierto.value = true;
}

async function eliminar(empresa: EmpresaItem) {
    const confirmado = await confirmarEliminacion(
        `la empresa «${empresa.nombre}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(empresa.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La empresa se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar la empresa.'),
    });
}
</script>

<template>
    <Head title="Empresas" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Empresas"
            descripcion="Estructura multiempresa: cada sucursal, colaborador y expediente pertenece a una empresa."
            :icono="Building2"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva empresa
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Empresas',
                    valor: estadisticas.total,
                    icono: Building2,
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
            placeholder="Buscar por nombre, razón social o RFC..."
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
            :datos="empresas"
            mensaje-vacio="No se encontraron empresas."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Building2"
                    titulo="Todavía no hay empresas"
                    descripcion="Crea la primera empresa para organizar sucursales y colaboradores."
                >
                    <Button size="sm" @click="abrirCrear">
                        <Plus class="size-4" />
                        Crear empresa
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-nombre="{ fila }">
                <div class="flex items-center gap-2">
                    <span
                        class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted"
                    >
                        <img
                            v-if="fila.logo_url"
                            :src="fila.logo_url"
                            alt=""
                            class="size-full object-cover"
                        />
                        <Building2
                            v-else
                            class="size-4 text-muted-foreground"
                        />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate font-medium">{{ fila.nombre }}</p>
                        <p
                            v-if="fila.razon_social"
                            class="truncate text-xs text-muted-foreground"
                        >
                            {{ fila.razon_social }}
                        </p>
                    </div>
                </div>
            </template>
            <template #celda-rfc="{ fila }">
                <span class="text-muted-foreground">{{ fila.rfc ?? '—' }}</span>
            </template>
            <template #celda-sucursales_count="{ fila }">
                <span
                    class="inline-flex items-center gap-1.5 text-muted-foreground"
                >
                    <MapPinned class="size-3.5" />
                    {{ fila.sucursales_count }}
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
                    :subtitulo="fila.rfc ?? 'Sin RFC'"
                >
                    <template #badge>
                        <EstadoBadge
                            :estado="fila.activo ? 'activo' : 'inactivo'"
                        />
                    </template>
                    <span class="inline-flex items-center gap-1.5">
                        <MapPinned class="size-3.5" />
                        {{ fila.sucursales_count }} sucursal(es)
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

    <EmpresaFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :empresa="empresaSeleccionada"
        :key="empresaSeleccionada?.id ?? 'nueva'"
    />
</template>
