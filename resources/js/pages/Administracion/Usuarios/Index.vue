<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Pencil, Plus, UserX } from '@lucide/vue';
import { ref } from 'vue';
import UsuarioFormDialog from '@/components/Administracion/UsuarioFormDialog.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import TableFilters from '@/components/DataTable/TableFilters.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

const columnas: ColumnaDataTable[] = [
    { clave: 'name', etiqueta: 'Colaborador' },
    { clave: 'numero_empleado', etiqueta: 'No. empleado' },
    { clave: 'sucursalPrincipal', etiqueta: 'Sucursal' },
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
        <div class="flex items-center justify-between">
            <Heading
                title="Colaboradores"
                description="Administra los colaboradores de la organización"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo colaborador
            </Button>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <TableFilters
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre, correo o número..."
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                @limpiar="limpiar"
            >
                <Select
                    :model-value="filtros.sucursal_id"
                    @update:model-value="
                        (v) => {
                            filtros.sucursal_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
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
                <Select
                    :model-value="filtros.estatus"
                    @update:model-value="
                        (v) => {
                            filtros.estatus = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-40"
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
            </TableFilters>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="usuarios"
            mensaje-vacio="No se encontraron colaboradores."
        >
            <template #celda-name="{ fila }">
                <div class="font-medium">
                    {{ fila.name }} {{ fila.apellidos }}
                </div>
                <div class="text-xs text-muted-foreground">
                    {{ fila.email }}
                </div>
            </template>
            <template #celda-sucursalPrincipal="{ fila }">
                {{ fila.sucursalPrincipal?.nombre ?? 'Sin asignar' }}
            </template>
            <template #celda-departamento="{ fila }">
                {{ fila.departamento?.nombre ?? 'Sin asignar' }}
            </template>
            <template #celda-estatus="{ fila }">
                <Badge
                    :variant="
                        fila.estatus === 'activo' ? 'default' : 'secondary'
                    "
                    >{{ fila.estatus }}</Badge
                >
            </template>
            <template #acciones="{ fila }">
                <div class="flex justify-end gap-1">
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Editar"
                        @click="abrirEditar(fila)"
                    >
                        <Pencil class="size-4" />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        title="Desactivar"
                        @click="desactivar(fila)"
                    >
                        <UserX class="size-4 text-destructive" />
                    </Button>
                </div>
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
