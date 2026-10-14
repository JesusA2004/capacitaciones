<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { crearColaborador } from '@/routes/rh/importaciones/filas';

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    open: boolean;
    importacionId: number;
    fila: number;
    nombreExcel: string;
    opciones: { sucursales: Opcion[]; departamentos: Opcion[]; puestos: Opcion[] };
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    puesto_id: '',
    departamento_id: '',
    sucursal_principal_id: '',
    numero_empleado: '',
});

function enviar() {
    form
        .transform((datos) => ({
            ...datos,
            departamento_id: datos.departamento_id || null,
            sucursal_principal_id: datos.sucursal_principal_id || null,
            numero_empleado: datos.numero_empleado || null,
        }))
        .post(crearColaborador.url({ importacion: props.importacionId, fila: props.fila }), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                emit('update:open', false);
            },
        });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Crear colaborador</DialogTitle>
                <DialogDescription>
                    «{{ nombreExcel }}» no existe en PEOPLE. Captura solo la
                    estructura que el reporte no trae — fecha de ingreso y
                    salario ya vienen del Excel.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label>Puesto</Label>
                    <Select v-model="form.puesto_id">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Elige un puesto" />
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
                    <p v-if="form.errors.puesto_id" class="text-xs text-destructive">
                        {{ form.errors.puesto_id }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Departamento (opcional)</Label>
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
                        <Label>Sucursal (opcional)</Label>
                        <Select v-model="form.sucursal_principal_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Remoto / corporativo" />
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

                <div class="grid gap-2">
                    <Label for="numero_empleado">Número de empleado (opcional)</Label>
                    <Input id="numero_empleado" v-model="form.numero_empleado" maxlength="30" />
                </div>

                <DialogFooter>
                    <Button type="button" variant="secondary" @click="emit('update:open', false)">Cancelar</Button>
                    <Button type="submit" :disabled="form.processing || !form.puesto_id">
                        <Spinner v-if="form.processing" />
                        Crear colaborador
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
