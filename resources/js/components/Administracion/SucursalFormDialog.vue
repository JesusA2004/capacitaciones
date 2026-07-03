<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { store, update } from '@/routes/administracion/sucursales';
import type { SucursalItem } from '@/types';

type ResponsableOpcion = { id: number; name: string; apellidos: string | null };

const props = defineProps<{
    open: boolean;
    sucursal?: SucursalItem | null;
    responsablesDisponibles: ResponsableOpcion[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: props.sucursal?.nombre ?? '',
    clave: props.sucursal?.clave ?? '',
    direccion: props.sucursal?.direccion ?? '',
    ciudad: props.sucursal?.ciudad ?? '',
    estado: props.sucursal?.estado ?? '',
    telefono: props.sucursal?.telefono ?? '',
    responsable_id: props.sucursal?.responsable_id
        ? String(props.sucursal.responsable_id)
        : '',
    activo: props.sucursal?.activo ?? true,
});

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.sucursal) {
        form.transform((datos) => ({
            ...datos,
            responsable_id: datos.responsable_id || null,
        })).put(update.url(props.sucursal.id), opciones);
    } else {
        form.transform((datos) => ({
            ...datos,
            responsable_id: datos.responsable_id || null,
        })).post(store.url(), opciones);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    sucursal ? 'Editar sucursal' : 'Nueva sucursal'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="nombre">Nombre</Label>
                        <Input id="nombre" v-model="form.nombre" autofocus />
                        <InputError :message="form.errors.nombre" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="clave">Clave</Label>
                        <Input
                            id="clave"
                            v-model="form.clave"
                            placeholder="p. ej. MTY01"
                        />
                        <InputError :message="form.errors.clave" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="direccion">Dirección</Label>
                    <Input id="direccion" v-model="form.direccion" />
                    <InputError :message="form.errors.direccion" />
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label for="ciudad">Ciudad</Label>
                        <Input id="ciudad" v-model="form.ciudad" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="estado">Estado</Label>
                        <Input id="estado" v-model="form.estado" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono</Label>
                        <Input id="telefono" v-model="form.telefono" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Responsable de sucursal</Label>
                    <Select v-model="form.responsable_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Sin asignar" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in responsablesDisponibles"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.name }} {{ opcion.apellidos }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.responsable_id" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.activo"
                        @update:model-value="(v) => (form.activo = !!v)"
                    />
                    Sucursal activa
                </label>

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
