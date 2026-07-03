<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/administracion/usuarios';
import type { EstadoUsuarioOpcion, OpcionSimple, UsuarioItem } from '@/types';

const props = defineProps<{
    open: boolean;
    usuario?: UsuarioItem | null;
    sucursalesDisponibles: OpcionSimple[];
    departamentosDisponibles: OpcionSimple[];
    puestosDisponibles: OpcionSimple[];
    rolesDisponibles: string[];
    estados: EstadoUsuarioOpcion[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    name: props.usuario?.name ?? '',
    apellidos: props.usuario?.apellidos ?? '',
    numero_empleado: props.usuario?.numero_empleado ?? '',
    email: props.usuario?.email ?? '',
    telefono: props.usuario?.telefono ?? '',
    sucursal_principal_id: props.usuario?.sucursal_principal_id
        ? String(props.usuario.sucursal_principal_id)
        : '',
    sucursales_adicionales: [] as string[],
    departamento_id: props.usuario?.departamento_id
        ? String(props.usuario.departamento_id)
        : '',
    puesto_id: props.usuario?.puesto_id ? String(props.usuario.puesto_id) : '',
    fecha_ingreso: props.usuario?.fecha_ingreso ?? '',
    estatus: props.usuario?.estatus ?? 'activo',
    roles: [...(props.usuario?.roles ?? [])] as string[],
});

function alternarEnLista(
    lista: string[],
    valor: string,
    marcado: boolean,
): string[] {
    return marcado
        ? [...new Set([...lista, valor])]
        : lista.filter((item) => item !== valor);
}

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };
    const transformado = form.transform((datos) => ({
        ...datos,
        departamento_id: datos.departamento_id || null,
        puesto_id: datos.puesto_id || null,
    }));

    if (props.usuario) {
        transformado.put(update.url(props.usuario.id), opciones);
    } else {
        transformado.post(store.url(), opciones);
    }
}
</script>

<template>
    <Sheet :open="open" @update:open="(valor) => emit('update:open', valor)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
            <SheetHeader>
                <SheetTitle>{{
                    usuario ? 'Editar colaborador' : 'Nuevo colaborador'
                }}</SheetTitle>
            </SheetHeader>

            <form class="grid gap-4 px-4 pb-6" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="name">Nombre(s)</Label>
                        <Input id="name" v-model="form.name" autofocus />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="apellidos">Apellidos</Label>
                        <Input id="apellidos" v-model="form.apellidos" />
                        <InputError :message="form.errors.apellidos" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="numero_empleado">Número de empleado</Label>
                        <Input
                            id="numero_empleado"
                            v-model="form.numero_empleado"
                        />
                        <InputError :message="form.errors.numero_empleado" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Correo electrónico</Label>
                        <Input id="email" v-model="form.email" type="email" />
                        <InputError :message="form.errors.email" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono</Label>
                        <Input id="telefono" v-model="form.telefono" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha_ingreso">Fecha de ingreso</Label>
                        <Input
                            id="fecha_ingreso"
                            v-model="form.fecha_ingreso"
                            type="date"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Sucursal principal</Label>
                    <Select v-model="form.sucursal_principal_id">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                placeholder="Selecciona una sucursal"
                            />
                        </SelectTrigger>
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
                    <InputError :message="form.errors.sucursal_principal_id" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Departamento</Label>
                        <Select v-model="form.departamento_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin departamento" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in departamentosDisponibles"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                >
                                    {{ opcion.nombre }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Puesto</Label>
                        <Select v-model="form.puesto_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin puesto" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in puestosDisponibles"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                >
                                    {{ opcion.nombre }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div v-if="usuario" class="grid gap-2">
                    <Label>Estatus</Label>
                    <Select v-model="form.estatus">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
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

                <div class="grid gap-2">
                    <Label>Sucursales adicionales autorizadas</Label>
                    <div
                        class="grid max-h-32 grid-cols-2 gap-2 overflow-y-auto rounded-md border p-2"
                    >
                        <label
                            v-for="opcion in sucursalesDisponibles"
                            :key="opcion.id"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="
                                    form.sucursales_adicionales.includes(
                                        String(opcion.id),
                                    )
                                "
                                @update:model-value="
                                    (v) =>
                                        (form.sucursales_adicionales =
                                            alternarEnLista(
                                                form.sucursales_adicionales,
                                                String(opcion.id),
                                                !!v,
                                            ))
                                "
                            />
                            {{ opcion.nombre }}
                        </label>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Roles</Label>
                    <div
                        class="grid max-h-32 grid-cols-2 gap-2 overflow-y-auto rounded-md border p-2"
                    >
                        <label
                            v-for="rol in rolesDisponibles"
                            :key="rol"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="form.roles.includes(rol)"
                                @update:model-value="
                                    (v) =>
                                        (form.roles = alternarEnLista(
                                            form.roles,
                                            rol,
                                            !!v,
                                        ))
                                "
                            />
                            {{ rol }}
                        </label>
                    </div>
                    <InputError :message="form.errors.roles" />
                </div>

                <p v-if="!usuario" class="text-sm text-muted-foreground">
                    Se enviará un correo al colaborador para que establezca su
                    propia contraseña.
                </p>

                <SheetFooter class="px-0">
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
