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
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/rh/incorporacion/invitaciones';
import type { OpcionesIncorporacionInvitacion } from '@/types/incorporacionInvitacion';

defineProps<{
    open: boolean;
    opciones: OpcionesIncorporacionInvitacion;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

// El QR es de acceso temporal a un formulario de incorporación: rango
// deliberadamente corto (1-24 horas), nunca días ni fechas libres.
const DURACIONES = [
    { value: '1', etiqueta: '1 hora' },
    { value: '2', etiqueta: '2 horas' },
    { value: '4', etiqueta: '4 horas' },
    { value: '8', etiqueta: '8 horas' },
    { value: '12', etiqueta: '12 horas' },
    { value: '24', etiqueta: '24 horas' },
];

const form = useForm({
    nombre_prellenado: '',
    email: '',
    telefono: '',
    empresa_id: '',
    sucursal_id: '',
    departamento_id: '',
    puesto_id: '',
    duracion: '24',
    observaciones: '',
});

function enviar() {
    const transformado = form.transform((datos) => ({
        nombre_prellenado: datos.nombre_prellenado || null,
        email: datos.email || null,
        telefono: datos.telefono || null,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        departamento_id: datos.departamento_id || null,
        puesto_id: datos.puesto_id || null,
        observaciones: datos.observaciones || null,
        duracion_horas: Number(datos.duracion),
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
                    >Nueva invitación de incorporación (QR)</DialogTitle
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="nombre_prellenado">Nombre (opcional)</Label>
                        <Input
                            id="nombre_prellenado"
                            v-model="form.nombre_prellenado"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email">Email (opcional)</Label>
                        <Input id="email" v-model="form.email" type="email" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="telefono">Teléfono (opcional)</Label>
                    <Input id="telefono" v-model="form.telefono" />
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
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Vigencia del QR</Label>
                    <Select v-model="form.duracion">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in DURACIONES"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        Pasado ese tiempo el QR deja de funcionar. Puedes
                        regenerarlo cuando quieras desde la invitación.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="observaciones">Observaciones internas</Label>
                    <Textarea
                        id="observaciones"
                        v-model="form.observaciones"
                        rows="2"
                        placeholder="Solo visible para RH, nunca para el colaborador."
                    />
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
                        Generar invitación
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
