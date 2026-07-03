<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Copy, Pencil, Plus, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ClonarRolDialog from '@/components/Administracion/ClonarRolDialog.vue';
import RolFormDialog from '@/components/Administracion/RolFormDialog.vue';
import type {
    PermisoItem,
    RolItem,
} from '@/components/Administracion/RolFormDialog.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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

defineProps<{
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
        <div class="flex items-center justify-between">
            <Heading
                title="Roles y permisos"
                description="Administra los roles del sistema y sus permisos asociados"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo rol
            </Button>
        </div>

        <div class="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Rol</TableHead>
                        <TableHead>Permisos</TableHead>
                        <TableHead>Colaboradores</TableHead>
                        <TableHead class="text-right">Acciones</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="rol in roles" :key="rol.id">
                        <TableCell class="font-medium">
                            {{ rol.nombre }}
                            <Badge
                                v-if="rol.es_protegido"
                                variant="secondary"
                                class="ml-2"
                                >protegido</Badge
                            >
                        </TableCell>
                        <TableCell
                            >{{ rol.permisos.length }} permiso(s)</TableCell
                        >
                        <TableCell>{{ rol.usuarios_count }}</TableCell>
                        <TableCell class="text-right">
                            <div class="flex justify-end gap-1">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Editar rol"
                                    @click="abrirEditar(rol)"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Clonar rol"
                                    @click="abrirClonar(rol)"
                                >
                                    <Copy class="size-4" />
                                </Button>
                                <Button
                                    v-if="!rol.es_protegido"
                                    variant="ghost"
                                    size="icon"
                                    title="Eliminar rol"
                                    @click="eliminar(rol)"
                                >
                                    <Trash2 class="size-4 text-destructive" />
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>
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
