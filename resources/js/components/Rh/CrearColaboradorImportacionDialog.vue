<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { crearColaborador } from '@/routes/rh/importaciones/filas';

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    open: boolean;
    importacionId: number;
    fila: number;
    nombreExcel: string;
    opciones: {
        sucursales: Opcion[];
        departamentos: Opcion[];
        puestos: Opcion[];
    };
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    puesto_id: '',
    departamento_id: '',
    sucursal_principal_id: '',
    numero_empleado: '',
    fecha_ingreso: '',
});

function enviar() {
    form.transform((datos) => ({
        ...datos,
        departamento_id: datos.departamento_id || null,
        sucursal_principal_id: datos.sucursal_principal_id || null,
        numero_empleado: datos.numero_empleado || null,
        fecha_ingreso: datos.fecha_ingreso || null,
    })).post(
        crearColaborador.url({
            importacion: props.importacionId,
            fila: props.fila,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                emit('update:open', false);
            },
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="max-h-[85vh] max-w-lg overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Crear colaborador</DialogTitle>
                <DialogDescription>
                    «{{ nombreExcel }}» no existe en PEOPLE. Captura solo la
                    estructura que el reporte no trae. El salario viene del
                    Excel; la fecha de ingreso del archivo NO se usa: captúrala
                    solo si está validada, o queda pendiente de validación.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label>Puesto</Label>
                    <SelectSimple
                        v-model="form.puesto_id"
                        :opciones="
                            opciones.puestos.map((o) => ({
                                value: String(o.id),
                                label: o.nombre,
                            }))
                        "
                        placeholder="Elige un puesto"
                    />
                    <p
                        v-if="form.errors.puesto_id"
                        class="text-xs text-destructive"
                    >
                        {{ form.errors.puesto_id }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Departamento (opcional)</Label>
                        <SelectSimple
                            v-model="form.departamento_id"
                            :opciones="
                                opciones.departamentos.map((o) => ({
                                    value: String(o.id),
                                    label: o.nombre,
                                }))
                            "
                            placeholder="Sin departamento"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Sucursal (opcional)</Label>
                        <SelectSimple
                            v-model="form.sucursal_principal_id"
                            :opciones="
                                opciones.sucursales.map((o) => ({
                                    value: String(o.id),
                                    label: o.nombre,
                                }))
                            "
                            placeholder="Remoto / corporativo"
                        />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="numero_empleado"
                        >Número de empleado (opcional)</Label
                    >
                    <Input
                        id="numero_empleado"
                        v-model="form.numero_empleado"
                        maxlength="30"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="fecha_ingreso"
                        >Fecha de ingreso validada (opcional)</Label
                    >
                    <Input
                        id="fecha_ingreso"
                        v-model="form.fecha_ingreso"
                        type="date"
                    />
                    <InputError :message="form.errors.fecha_ingreso" />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.puesto_id"
                    >
                        <Spinner v-if="form.processing" />
                        Crear colaborador
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
