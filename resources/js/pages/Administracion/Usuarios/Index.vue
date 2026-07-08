<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, Plus, Users, XCircle } from '@lucide/vue';
import { computed, ref } from 'vue';
import UsuarioFormDialog from '@/components/Administracion/UsuarioFormDialog.vue';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/administracion/usuarios';
import type {
    EstadisticasActivoInactivo,
    EstadoUsuarioOpcion,
    OpcionSimple,
    RespuestaPaginada,
    UsuarioItem,
} from '@/types';

const props = defineProps<{
    usuarios: RespuestaPaginada<UsuarioItem>;
    filtros: { busqueda?: string; sucursal_id?: string; estatus?: string };
    sucursalesDisponibles: OpcionSimple[];
    departamentosDisponibles: OpcionSimple[];
    puestosDisponibles: (OpcionSimple & { departamento_id: number | null })[];
    rolesDisponibles: string[];
    estados: EstadoUsuarioOpcion[];
    estadisticas: EstadisticasActivoInactivo;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Colaboradores', href: index.url() },
        ],
    },
});

const { filtros, aplicarConDebounce, aplicar, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        estatus: props.filtros.estatus ?? '',
    },
);
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const contadorFiltrosActivos = computed(
    () =>
        [filtros.sucursal_id, filtros.estatus].filter((valor) => valor !== '')
            .length,
);

const columnas: ColumnaDataTable[] = [
    { clave: 'name', etiqueta: 'Colaborador' },
    { clave: 'numero_empleado', etiqueta: 'No. empleado' },
    { clave: 'sucursal_principal', etiqueta: 'Sucursal' },
    { clave: 'departamento', etiqueta: 'Departamento' },
    { clave: 'estatus', etiqueta: 'Estatus' },
];

const dialogAbierto = ref(false);
const seleccionado = ref<UsuarioItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(usuario: UsuarioItem) {
    seleccionado.value = usuario;
    dialogAbierto.value = true;
}

async function desactivar(usuario: UsuarioItem) {
    const confirmado = await confirmarEliminacion(
        `al colaborador «${usuario.name} ${usuario.apellidos ?? ''}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(usuario.id), {
        preserveScroll: true,
        onSuccess: () =>
            mostrarExito('El colaborador se desactivó correctamente.'),
        onError: () =>
            mostrarError('No fue posible desactivar al colaborador.'),
    });
}
</script>

<template>
    <Head title="Colaboradores" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Colaboradores"
            descripcion="Administra colaboradores, sucursal, puesto, roles y asignaciones automáticas."
            :icono="Users"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo colaborador
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Colaboradores',
                    valor: estadisticas.total,
                    icono: Users,
                },
                {
                    etiqueta: 'Activos',
                    valor: estadisticas.activos,
                    icono: CheckCircle2,
                    tono: 'success',
                },
                {
                    etiqueta: 'Inactivos/suspendidos',
                    valor: estadisticas.inactivos,
                    icono: XCircle,
                    tono: 'danger',
                },
            ]"
        />

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar por nombre, correo o número..."
            :contador-filtros-activos="contadorFiltrosActivos"
            titulo-filtros="Filtrar colaboradores"
            descripcion-filtros="Acota la lista por sucursal o estatus."
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @limpiar="limpiar"
            @aplicar-filtros="aplicar"
        >
            <template #filtros>
                <div class="grid gap-2">
                    <Label>Sucursal</Label>
                    <Select
                        :model-value="filtros.sucursal_id"
                        @update:model-value="
                            (v) => (filtros.sucursal_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger class="w-full"
                            ><SelectValue placeholder="Todas las sucursales"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in sucursalesDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.nombre }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <div class="grid gap-2">
                    <Label>Estatus</Label>
                    <Select
                        :model-value="filtros.estatus"
                        @update:model-value="
                            (v) => (filtros.estatus = String(v ?? ''))
                        "
                    >
                        <SelectTrigger class="w-full"
                            ><SelectValue placeholder="Todos los estatus"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in estados"
                                :key="opcion.value"
                                :value="opcion.value"
                            >
                                {{ opcion.etiqueta }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </template>
        </CrudToolbar>

        <DataTable
            :columnas="columnas"
            :datos="usuarios"
            mensaje-vacio="No se encontraron colaboradores."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Users"
                    titulo="Todavía no hay colaboradores"
                    descripcion="Crea el primer colaborador para empezar a asignarle capacitación."
                >
                    <Button size="sm" @click="abrirCrear">
                        <Plus class="size-4" />
                        Crear colaborador
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-name="{ fila }">
                <div class="font-medium">
                    {{ fila.name }} {{ fila.apellidos }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ fila.email }}
                </div>
            </template>
            <template #celda-sucursal_principal="{ fila }">
                {{ fila.sucursal_principal?.nombre ?? 'Sin asignar' }}
            </template>
            <template #celda-departamento="{ fila }">
                {{ fila.departamento?.nombre ?? 'Sin asignar' }}
            </template>
            <template #celda-estatus="{ fila }">
                <EstadoBadge :estado="fila.estatus" />
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirEditar(fila)"
                        >Editar</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        variant="destructive"
                        @select="desactivar(fila)"
                        >Desactivar</DropdownMenuItem
                    >
                </CrudActionMenu>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="`${fila.name} ${fila.apellidos ?? ''}`"
                    :subtitulo="fila.email"
                >
                    <template #badge>
                        <EstadoBadge :estado="fila.estatus" />
                    </template>
                    <span>{{
                        fila.sucursal_principal?.nombre ?? 'Sin sucursal'
                    }}</span>
                    <span>{{
                        fila.departamento?.nombre ?? 'Sin departamento'
                    }}</span>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditar(fila)"
                                >Editar</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                variant="destructive"
                                @select="desactivar(fila)"
                                >Desactivar</DropdownMenuItem
                            >
                        </CrudActionMenu>
                    </template>
                </CrudMobileCard>
            </template>
        </DataTable>
    </div>

    <UsuarioFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :usuario="seleccionado"
        :sucursales-disponibles="sucursalesDisponibles"
        :departamentos-disponibles="departamentosDisponibles"
        :puestos-disponibles="puestosDisponibles"
        :roles-disponibles="rolesDisponibles"
        :estados="estados"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
