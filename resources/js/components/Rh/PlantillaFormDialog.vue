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
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/rh/plantillas';
import type { OpcionesPlantillas, PlantillaItem } from '@/types';

const props = defineProps<{
    open: boolean;
    plantilla?: PlantillaItem | null;
    opciones: OpcionesPlantillas;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: props.plantilla?.nombre ?? '',
    tipo: props.plantilla?.tipo ?? '',
    descripcion: props.plantilla?.descripcion ?? '',
    empresa_id: props.plantilla?.empresa
        ? String(props.plantilla.empresa.id)
        : '',
    sucursal_id: props.plantilla?.sucursal
        ? String(props.plantilla.sucursal.id)
        : '',
    puesto_id: props.plantilla?.puesto ? String(props.plantilla.puesto.id) : '',
    activo: props.plantilla?.activo ?? true,
    archivo: null as File | null,
});

function alSeleccionarArchivo(event: Event) {
    const input = event.target as HTMLInputElement;
    form.archivo = input.files?.[0] ?? null;
}

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        puesto_id: datos.puesto_id || null,
    }));

    const opciones = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.plantilla) {
        transformado.post(update.url(props.plantilla.id), opciones);
    } else {
        transformado.post(store.url(), opciones);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{
                    plantilla ? 'Editar plantilla' : 'Nueva plantilla'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre">Nombre</Label>
                    <Input id="nombre" v-model="form.nombre" autofocus />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div class="grid gap-2">
                    <Label>Tipo</Label>
                    <Select v-model="form.tipo">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Selecciona un tipo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.tipos"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.tipo" />
                </div>

                <div class="grid grid-cols-3 gap-4">
                    <div class="grid gap-2">
                        <Label>Empresa</Label>
                        <Select v-model="form.empresa_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Todas" />
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
                                <SelectValue placeholder="Todas" />
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
                    <div class="grid gap-2">
                        <Label>Puesto</Label>
                        <Select v-model="form.puesto_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Todos" />
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
                </div>

                <div class="grid gap-2">
                    <Label for="descripcion">Descripción</Label>
                    <Textarea
                        id="descripcion"
                        v-model="form.descripcion"
                        rows="2"
                    />
                    <InputError :message="form.errors.descripcion" />
                </div>

                <div class="grid gap-2">
                    <Label for="archivo">{{
                        plantilla
                            ? 'Reemplazar archivo (opcional)'
                            : 'Archivo DOCX'
                    }}</Label>
                    <Input
                        id="archivo"
                        type="file"
                        accept=".docx"
                        @change="alSeleccionarArchivo"
                    />
                    <InputError :message="form.errors.archivo" />
                    <p v-if="plantilla" class="text-xs text-muted-foreground">
                        Archivo actual: {{ plantilla.original_name }} (v{{
                            plantilla.version
                        }})
                    </p>
                </div>

                <label v-if="plantilla" class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.activo"
                        @update:model-value="(v) => (form.activo = !!v)"
                    />
                    Plantilla activa
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
