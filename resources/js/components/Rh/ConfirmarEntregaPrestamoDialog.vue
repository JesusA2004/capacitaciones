<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { activar as activarPrestamo } from '@/routes/rh/expedientes/prestamos';
import type { PrestamoItem } from '@/types';

const props = defineProps<{
    open: boolean;
    prestamo: PrestamoItem;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

function moneda(valor: number): string {
    return valor.toLocaleString('es-MX', { style: 'currency', currency: 'MXN' });
}

const form = useForm({
    fecha_otorgamiento: new Date().toISOString().slice(0, 10),
    fecha_primer_descuento: '',
    periodicidad: props.prestamo.periodicidad || 'quincenal',
    pago_programado: props.prestamo.pago_programado,
});

function enviar() {
    form.post(activarPrestamo.url(props.prestamo.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Confirmar entrega del préstamo</DialogTitle>
                <DialogDescription>
                    Monto aprobado: {{ moneda(prestamo.monto_original) }} ·
                    {{ prestamo.plazo }} pagos. Al confirmar, el préstamo
                    pasa a activo y empieza a contar como deuda vigente.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="fecha-otorgamiento"
                            >Fecha de otorgamiento</Label
                        >
                        <Input
                            id="fecha-otorgamiento"
                            v-model="form.fecha_otorgamiento"
                            type="date"
                        />
                        <InputError :message="form.errors.fecha_otorgamiento" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha-primer-descuento"
                            >Fecha primer descuento</Label
                        >
                        <Input
                            id="fecha-primer-descuento"
                            v-model="form.fecha_primer_descuento"
                            type="date"
                        />
                        <InputError
                            :message="form.errors.fecha_primer_descuento"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Periodicidad</Label>
                        <Select v-model="form.periodicidad">
                            <SelectTrigger class="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="semanal">Semanal</SelectItem>
                                <SelectItem value="quincenal"
                                    >Quincenal</SelectItem
                                >
                                <SelectItem value="mensual">Mensual</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.periodicidad" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pago-programado">Pago programado</Label>
                        <Input
                            id="pago-programado"
                            v-model.number="form.pago_programado"
                            type="number"
                            step="0.01"
                            min="0.01"
                        />
                        <InputError :message="form.errors.pago_programado" />
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
                        Confirmar entrega
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
