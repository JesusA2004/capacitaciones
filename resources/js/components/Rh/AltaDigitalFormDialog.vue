<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/rh/altas';
import type { OpcionesReclutamiento } from '@/types';

defineProps<{
    open: boolean;
    opciones: OpcionesReclutamiento;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    empresa_id: '',
    sucursal_id: '',
    departamento_id: '',
    puesto_id: '',
    nombre: '',
    apellidos: '',
    correo: '',
    telefono: '',
    fecha_ingreso_propuesta: '',
});

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        departamento_id: datos.departamento_id || null,
        puesto_id: datos.puesto_id || null,
        fecha_ingreso_propuesta: datos.fecha_ingreso_propuesta || null,
    }));

    transformado.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle
                    >Nueva alta digital (preregistro manual)</DialogTitle
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input id="nombre" v-model="form.nombre" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="apellidos">Apellidos</Label>
                        <Input id="apellidos" v-model="form.apellidos" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="correo">Correo</Label>
                        <Input id="correo" v-model="form.correo" type="email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono</Label>
                        <Input id="telefono" v-model="form.telefono" />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Empresa</Label>
                        <Select v-model="form.empresa_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin empresa" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.empresas"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label>Sucursal</Label>
                        <Select v-model="form.sucursal_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin sucursal" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.sucursales"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Puesto</Label>
                        <Select v-model="form.puesto_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin puesto" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.puestos"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha_ingreso"
                            >Fecha de ingreso propuesta</Label
                        >
                        <Input
                            id="fecha_ingreso"
                            v-model="form.fecha_ingreso_propuesta"
                            type="date"
                        />
                    </div>
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
                        Crear
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
