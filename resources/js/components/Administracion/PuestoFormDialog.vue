<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import SelectSimple from '@/components/Common/SelectSimple.vue';
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
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/administracion/puestos';
import type { OpcionSimple, PuestoItem } from '@/types';

const props = defineProps<{
    open: boolean;
    puesto?: PuestoItem | null;
    departamentosDisponibles: OpcionSimple[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: props.puesto?.nombre ?? '',
    departamento_id: props.puesto?.departamento_id
        ? String(props.puesto.departamento_id)
        : '',
    descripcion: props.puesto?.descripcion ?? '',
    activo: props.puesto?.activo ?? true,
    meses_periodo_prueba: props.puesto?.meses_periodo_prueba
        ? String(props.puesto.meses_periodo_prueba)
        : '',
});

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };
    const transformado = form.transform((datos) => ({
        ...datos,
        departamento_id: datos.departamento_id || null,
        meses_periodo_prueba: datos.meses_periodo_prueba
            ? Number(datos.meses_periodo_prueba)
            : null,
    }));

    if (props.puesto) {
        transformado.put(update.url(props.puesto.id), opciones);
    } else {
        transformado.post(store.url(), opciones);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    puesto ? 'Editar puesto' : 'Nuevo puesto'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre">Nombre</Label>
                    <Input id="nombre" v-model="form.nombre" autofocus />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div class="grid gap-2">
                    <Label>Departamento</Label>
                    <SelectSimple
                        v-model="form.departamento_id"
                        :opciones="
                            departamentosDisponibles.map((o) => ({
                                value: String(o.id),
                                label: o.nombre,
                            }))
                        "
                        placeholder="Sin departamento"
                        opcion-vacia="Sin departamento"
                        placeholder-busqueda="Buscar departamento…"
                    />
                    <InputError :message="form.errors.departamento_id" />
                </div>

                <div class="grid gap-2">
                    <Label for="descripcion">Descripción</Label>
                    <Input id="descripcion" v-model="form.descripcion" />
                    <InputError :message="form.errors.descripcion" />
                </div>

                <div class="grid gap-2">
                    <Label for="meses_periodo_prueba"
                        >Duración del periodo de prueba (meses)</Label
                    >
                    <Input
                        id="meses_periodo_prueba"
                        v-model="form.meses_periodo_prueba"
                        type="number"
                        min="1"
                        max="12"
                        placeholder="Sin configurar"
                    />
                    <p class="text-xs text-muted-foreground">
                        Contrato de capacitación inicial. Sin esta duración no
                        se calculan sus contratos ni su vencimiento.
                    </p>
                    <InputError :message="form.errors.meses_periodo_prueba" />
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.activo"
                        @update:model-value="(v) => (form.activo = !!v)"
                    />
                    Puesto activo
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
