<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/rh/vacantes';
import type { OpcionesReclutamiento, VacanteItem } from '@/types';

const props = defineProps<{
    open: boolean;
    vacante?: VacanteItem | null;
    opciones: OpcionesReclutamiento;
    /** Precarga al crear (p. ej. desde "Crear vacante" en Jerarquía de puestos). */
    prefill?: {
        puesto_id?: number;
        departamento_id?: number;
        empresa_id?: number;
        sucursal_id?: number;
        motivo?: string;
    } | null;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

function idComo(valor: { id: number } | null | undefined): string {
    return valor?.id ? String(valor.id) : '';
}

const form = useForm({
    empresa_id:
        idComo(props.vacante?.empresa) ||
        String(props.prefill?.empresa_id ?? ''),
    sucursal_id: props.vacante?.sucursal_id
        ? String(props.vacante.sucursal_id)
        : String(props.prefill?.sucursal_id ?? ''),
    departamento_id:
        idComo(props.vacante?.departamento) ||
        String(props.prefill?.departamento_id ?? ''),
    puesto_id:
        idComo(props.vacante?.puesto) || String(props.prefill?.puesto_id ?? ''),
    motivo: props.vacante?.motivo ?? props.prefill?.motivo ?? '',
    fecha_apertura:
        props.vacante?.fecha_apertura?.slice(0, 10) ??
        new Date().toISOString().slice(0, 10),
    fecha_estimada_cobertura:
        props.vacante?.fecha_estimada_cobertura?.slice(0, 10) ?? '',
    observaciones: props.vacante?.observaciones ?? '',
});

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        departamento_id: datos.departamento_id || null,
        puesto_id: datos.puesto_id || null,
        fecha_estimada_cobertura: datos.fecha_estimada_cobertura || null,
    }));

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.vacante) {
        transformado.put(update.url(props.vacante.id), opciones);
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
                    vacante ? 'Editar vacante' : 'Nueva vacante'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
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
                        <Label>Departamento</Label>
                        <Select v-model="form.departamento_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin departamento" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in opciones.departamentos"
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
                        <InputError :message="form.errors.puesto_id" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Motivo</Label>
                    <Select v-model="form.motivo">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Selecciona un motivo" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.motivos"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.motivo" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="fecha_apertura">Fecha de apertura</Label>
                        <Input
                            id="fecha_apertura"
                            v-model="form.fecha_apertura"
                            type="date"
                        />
                        <InputError :message="form.errors.fecha_apertura" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="fecha_cobertura"
                            >Fecha estimada de cobertura</Label
                        >
                        <Input
                            id="fecha_cobertura"
                            v-model="form.fecha_estimada_cobertura"
                            type="date"
                        />
                        <InputError
                            :message="form.errors.fecha_estimada_cobertura"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="3"
                    />
                    <InputError :message="form.errors.observaciones" />
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
