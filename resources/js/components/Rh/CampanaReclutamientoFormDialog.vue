<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
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
import { store, update } from '@/routes/rh/campanas';
import type { CampanaReclutamientoItem, OpcionesCampanas } from '@/types';

const props = defineProps<{
    open: boolean;
    campana?: CampanaReclutamientoItem | null;
    opciones: OpcionesCampanas;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const meses = [
    { value: '1', etiqueta: 'Enero' },
    { value: '2', etiqueta: 'Febrero' },
    { value: '3', etiqueta: 'Marzo' },
    { value: '4', etiqueta: 'Abril' },
    { value: '5', etiqueta: 'Mayo' },
    { value: '6', etiqueta: 'Junio' },
    { value: '7', etiqueta: 'Julio' },
    { value: '8', etiqueta: 'Agosto' },
    { value: '9', etiqueta: 'Septiembre' },
    { value: '10', etiqueta: 'Octubre' },
    { value: '11', etiqueta: 'Noviembre' },
    { value: '12', etiqueta: 'Diciembre' },
];

const ahora = new Date();

function idComo(valor: { id: number } | null | undefined): string {
    return valor?.id ? String(valor.id) : '';
}

const form = useForm({
    mes: props.campana ? String(props.campana.mes) : String(ahora.getMonth() + 1),
    anio: props.campana ? String(props.campana.anio) : String(ahora.getFullYear()),
    canal: props.campana?.canal ?? '',
    empresa_id: idComo(props.campana?.empresa),
    sucursal_id: props.campana?.sucursal_id ? String(props.campana.sucursal_id) : '',
    departamento_id: idComo(props.campana?.departamento),
    puesto_id: props.campana?.puesto_id ? String(props.campana.puesto_id) : '',
    monto: props.campana ? String(props.campana.monto) : '',
    candidatos_generados:
        props.campana?.candidatos_generados !== null &&
        props.campana?.candidatos_generados !== undefined
            ? String(props.campana.candidatos_generados)
            : '',
    observaciones: props.campana?.observaciones ?? '',
});

const sucursalesFiltradas = computed(() =>
    form.empresa_id
        ? props.opciones.sucursales.filter(
              (s) => String(s.empresa_id) === form.empresa_id,
          )
        : props.opciones.sucursales,
);

const puestosFiltrados = computed(() =>
    form.departamento_id
        ? props.opciones.puestos.filter(
              (p) => String(p.departamento_id) === form.departamento_id,
          )
        : props.opciones.puestos,
);

function enviar() {
    const transformado = form.transform((datos) => ({
        ...datos,
        empresa_id: datos.empresa_id || null,
        sucursal_id: datos.sucursal_id || null,
        departamento_id: datos.departamento_id || null,
        puesto_id: datos.puesto_id || null,
        candidatos_generados: datos.candidatos_generados || null,
    }));

    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.campana) {
        transformado.put(update.url(props.campana.id), opciones);
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
                    campana ? 'Editar campaña' : 'Nueva campaña'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Mes</Label>
                        <Select v-model="form.mes">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Mes" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in meses"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                    >{{ opcion.etiqueta }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.mes" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="anio">Año</Label>
                        <Input
                            id="anio"
                            v-model="form.anio"
                            type="number"
                            min="2000"
                            max="2100"
                        />
                        <InputError :message="form.errors.anio" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label>Canal</Label>
                    <Select v-model="form.canal">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Selecciona un canal" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.canales"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.canal" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label>Empresa (opcional)</Label>
                        <Select
                            v-model="form.empresa_id"
                            @update:model-value="form.sucursal_id = ''"
                        >
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
                        <Label>Sucursal (opcional)</Label>
                        <Select v-model="form.sucursal_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Sin sucursal" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in sucursalesFiltradas"
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
                        <Label>Departamento (opcional)</Label>
                        <Select
                            v-model="form.departamento_id"
                            @update:model-value="form.puesto_id = ''"
                        >
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
                        <Label>Puesto (opcional)</Label>
                        <Select v-model="form.puesto_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="General (sin puesto)" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in puestosFiltrados"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>
                        <p class="text-xs text-muted-foreground">
                            Déjalo vacío si es gasto general, no dirigido a
                            una posición en particular.
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="monto">Monto</Label>
                        <div class="relative">
                            <span
                                class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-sm text-muted-foreground"
                                >$</span
                            >
                            <Input
                                id="monto"
                                v-model="form.monto"
                                type="number"
                                min="0"
                                step="0.01"
                                placeholder="0.00"
                                class="pl-6"
                            />
                        </div>
                        <InputError :message="form.errors.monto" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="candidatos_generados"
                            >Candidatos generados (opcional)</Label
                        >
                        <Input
                            id="candidatos_generados"
                            v-model="form.candidatos_generados"
                            type="number"
                            min="0"
                            placeholder="Auto (por fuente)"
                        />
                        <p class="text-xs text-muted-foreground">
                            Si lo dejas vacío, se estima contando candidatos
                            cuya fuente coincide con el canal en este periodo.
                        </p>
                        <InputError
                            :message="form.errors.candidatos_generados"
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
