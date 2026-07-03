<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RolController from '@/actions/App/Http/Controllers/Administracion/RolController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';

export type PermisoItem = {
    id: number;
    name: string;
};

export type RolItem = {
    id: number;
    nombre: string;
    permisos: string[];
    usuarios_count: number;
    es_protegido: boolean;
};

const props = defineProps<{
    open: boolean;
    rol?: RolItem | null;
    permisosAgrupados: Record<string, PermisoItem[]>;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: props.rol?.nombre ?? '',
    permisos: [...(props.rol?.permisos ?? [])] as string[],
});

function alternarPermiso(nombre: string, marcado: boolean) {
    if (marcado) {
        if (!form.permisos.includes(nombre)) {
            form.permisos.push(nombre);
        }
    } else {
        form.permisos = form.permisos.filter((permiso) => permiso !== nombre);
    }
}

function alternarModulo(permisosModulo: PermisoItem[], marcado: boolean) {
    permisosModulo.forEach((permiso) => alternarPermiso(permiso.name, marcado));
}

function moduloCompleto(permisosModulo: PermisoItem[]): boolean {
    return permisosModulo.every((permiso) =>
        form.permisos.includes(permiso.name),
    );
}

function enviar() {
    if (props.rol) {
        form.put(RolController.update.url(props.rol.id), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
    } else {
        form.post(RolController.store.url(), {
            preserveScroll: true,
            onSuccess: () => emit('update:open', false),
        });
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>{{
                    rol ? 'Editar rol' : 'Nuevo rol'
                }}</DialogTitle>
                <DialogDescription>
                    Define el nombre del rol y selecciona los permisos que
                    tendrán los colaboradores asignados.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-6" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre">Nombre del rol</Label>
                    <Input
                        id="nombre"
                        v-model="form.nombre"
                        placeholder="por ejemplo: coordinador_capacitacion"
                        :disabled="rol?.es_protegido"
                    />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div class="space-y-4">
                    <Label>Permisos</Label>
                    <div
                        v-for="(permisosModulo, modulo) in permisosAgrupados"
                        :key="modulo"
                        class="rounded-md border p-3"
                    >
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-sm font-medium capitalize">{{
                                modulo
                            }}</span>
                            <label
                                class="flex items-center gap-2 text-xs text-muted-foreground"
                            >
                                <Checkbox
                                    :model-value="
                                        moduloCompleto(permisosModulo)
                                    "
                                    @update:model-value="
                                        (valor) =>
                                            alternarModulo(
                                                permisosModulo,
                                                !!valor,
                                            )
                                    "
                                />
                                Seleccionar todo
                            </label>
                        </div>
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            <label
                                v-for="permiso in permisosModulo"
                                :key="permiso.id"
                                class="flex items-center gap-2 text-sm"
                            >
                                <Checkbox
                                    :model-value="
                                        form.permisos.includes(permiso.name)
                                    "
                                    @update:model-value="
                                        (valor) =>
                                            alternarPermiso(
                                                permiso.name,
                                                !!valor,
                                            )
                                    "
                                />
                                {{ permiso.name }}
                            </label>
                        </div>
                    </div>
                    <InputError :message="form.errors.permisos" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
