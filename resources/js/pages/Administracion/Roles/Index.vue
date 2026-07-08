<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Lock, Plus, ShieldCheck, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import ClonarRolDialog from '@/components/Administracion/ClonarRolDialog.vue';
import RolFormDialog from '@/components/Administracion/RolFormDialog.vue';
import type {
    PermisoItem,
    RolItem,
} from '@/components/Administracion/RolFormDialog.vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { destroy } from '@/routes/administracion/roles';

const props = defineProps<{
    roles: RolItem[];
    permisosAgrupados: Record<string, PermisoItem[]>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Roles y permisos', href: '/administracion/roles' },
        ],
    },
});

const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const busqueda = ref('');

const rolesFiltrados = computed(() => {
    const termino = busqueda.value.trim().toLowerCase();

    if (!termino) {
        return props.roles;
    }

    return props.roles.filter((rol) =>
        rol.nombre.toLowerCase().includes(termino),
    );
});

const totalPermisosDisponibles = computed(
    () => Object.values(props.permisosAgrupados).flat().length,
);
const totalColaboradoresConRol = computed(() =>
    props.roles.reduce((total, rol) => total + rol.usuarios_count, 0),
);

const dialogFormAbierto = ref(false);
const dialogClonarAbierto = ref(false);
const rolSeleccionado = ref<RolItem | null>(null);

function abrirCrear() {
    rolSeleccionado.value = null;
    dialogFormAbierto.value = true;
}

function abrirEditar(rol: RolItem) {
    rolSeleccionado.value = rol;
    dialogFormAbierto.value = true;
}

function abrirClonar(rol: RolItem) {
    rolSeleccionado.value = rol;
    dialogClonarAbierto.value = true;
}

async function eliminar(rol: RolItem) {
    const confirmado = await confirmarEliminacion(`el rol «${rol.nombre}»`);

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(rol.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('El rol se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar el rol.'),
    });
}
</script>

<template>
    <Head title="Roles y permisos" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Roles y permisos"
            descripcion="Crea roles y controla qué puede hacer cada uno mediante permisos."
            :icono="ShieldCheck"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo rol
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                { etiqueta: 'Roles', valor: roles.length, icono: ShieldCheck },
                {
                    etiqueta: 'Colaboradores con rol',
                    valor: totalColaboradoresConRol,
                    icono: Users,
                    tono: 'info',
                },
                {
                    etiqueta: 'Permisos disponibles',
                    valor: totalPermisosDisponibles,
                    icono: Lock,
                },
            ]"
        />

        <CrudSearchInput
            v-model="busqueda"
            placeholder="Buscar rol por nombre..."
        />

        <CrudEmptyState
            v-if="rolesFiltrados.length === 0"
            :icono="ShieldCheck"
            titulo="Sin roles que coincidan"
            descripcion="No hay ningún rol con ese nombre. Ajusta la búsqueda o crea uno nuevo."
        >
            <Button size="sm" @click="abrirCrear">
                <Plus class="size-4" />
                Crear rol
            </Button>
        </CrudEmptyState>

        <template v-else>
            <div
                class="hidden overflow-hidden rounded-2xl border border-border/60 shadow-sm sm:block"
            >
                <Table>
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead>Rol</TableHead>
                            <TableHead>Permisos</TableHead>
                            <TableHead>Colaboradores</TableHead>
                            <TableHead class="text-right">Acciones</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="rol in rolesFiltrados" :key="rol.id">
                            <TableCell class="font-medium">
                                {{ rol.nombre }}
                                <Badge
                                    v-if="rol.es_protegido"
                                    variant="secondary"
                                    class="ml-2"
                                    >protegido</Badge
                                >
                            </TableCell>
                            <TableCell class="text-muted-foreground"
                                >{{ rol.permisos.length }} permiso(s)</TableCell
                            >
                            <TableCell class="text-muted-foreground">{{
                                rol.usuarios_count
                            }}</TableCell>
                            <TableCell class="text-right">
                                <CrudActionMenu>
                                    <DropdownMenuItem @select="abrirEditar(rol)"
                                        >Editar</DropdownMenuItem
                                    >
                                    <DropdownMenuItem @select="abrirClonar(rol)"
                                        >Clonar</DropdownMenuItem
                                    >
                                    <DropdownMenuItem
                                        v-if="!rol.es_protegido"
                                        variant="destructive"
                                        @select="eliminar(rol)"
                                    >
                                        Eliminar
                                    </DropdownMenuItem>
                                </CrudActionMenu>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <div class="flex flex-col gap-3 sm:hidden">
                <CrudMobileCard
                    v-for="rol in rolesFiltrados"
                    :key="rol.id"
                    :titulo="rol.nombre"
                    :subtitulo="`${rol.permisos.length} permiso(s)`"
                >
                    <template #badge>
                        <Badge v-if="rol.es_protegido" variant="secondary"
                            >protegido</Badge
                        >
                    </template>
                    <span class="inline-flex items-center gap-1.5">
                        <Users class="size-3.5" />
                        {{ rol.usuarios_count }} colaborador(es)
                    </span>
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditar(rol)"
                                >Editar</DropdownMenuItem
                            >
                            <DropdownMenuItem @select="abrirClonar(rol)"
                                >Clonar</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                v-if="!rol.es_protegido"
                                variant="destructive"
                                @select="eliminar(rol)"
                            >
                                Eliminar
                            </DropdownMenuItem>
                        </CrudActionMenu>
                    </template>
                </CrudMobileCard>
            </div>
        </template>
    </div>

    <RolFormDialog
        v-if="dialogFormAbierto"
        v-model:open="dialogFormAbierto"
        :rol="rolSeleccionado"
        :permisos-agrupados="permisosAgrupados"
        :key="rolSeleccionado?.id ?? 'nuevo'"
    />

    <ClonarRolDialog
        v-if="dialogClonarAbierto"
        v-model:open="dialogClonarAbierto"
        :rol="rolSeleccionado"
    />
</template>
