<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type {
    DestinoAsignacionForm,
    OpcionRol,
    OpcionSimple,
    ResponsableOpcion,
} from '@/types';

const props = defineProps<{
    modelValue: DestinoAsignacionForm;
    sucursales: OpcionSimple[];
    departamentos: OpcionSimple[];
    puestos: OpcionSimple[];
    roles: OpcionRol[];
    usuarios: ResponsableOpcion[];
}>();

const emit = defineEmits<{
    'update:modelValue': [valor: DestinoAsignacionForm];
    eliminar: [];
}>();

const tiposDestino = [
    { value: 'usuario', etiqueta: 'Colaborador específico' },
    { value: 'sucursal', etiqueta: 'Sucursal' },
    { value: 'departamento', etiqueta: 'Departamento' },
    { value: 'puesto', etiqueta: 'Puesto' },
    { value: 'rol', etiqueta: 'Rol' },
    { value: 'todos', etiqueta: 'Todos los colaboradores' },
];

const opcionesObjetivo = computed(() => {
    switch (props.modelValue.tipo) {
        case 'sucursal':
            return props.sucursales.map((o) => ({
                id: o.id,
                etiqueta: o.nombre,
            }));
        case 'departamento':
            return props.departamentos.map((o) => ({
                id: o.id,
                etiqueta: o.nombre,
            }));
        case 'puesto':
            return props.puestos.map((o) => ({ id: o.id, etiqueta: o.nombre }));
        case 'rol':
            return props.roles.map((o) => ({ id: o.id, etiqueta: o.name }));
        case 'usuario':
            return props.usuarios.map((o) => ({
                id: o.id,
                etiqueta: `${o.name} ${o.apellidos ?? ''}`,
            }));
        default:
            return [];
    }
});

function actualizarTipo(tipo: string) {
    emit('update:modelValue', { tipo, id: null });
}

function actualizarObjetivo(id: string) {
    emit('update:modelValue', { ...props.modelValue, id: Number(id) });
}
</script>

<template>
    <div class="flex items-center gap-2">
        <Select
            :model-value="modelValue.tipo"
            @update:model-value="(v) => actualizarTipo(String(v))"
        >
            <SelectTrigger class="w-52"><SelectValue /></SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="opcion in tiposDestino"
                    :key="opcion.value"
                    :value="opcion.value"
                >
                    {{ opcion.etiqueta }}
                </SelectItem>
            </SelectContent>
        </Select>

        <Select
            v-if="modelValue.tipo !== 'todos'"
            :model-value="modelValue.id ? String(modelValue.id) : ''"
            @update:model-value="(v) => actualizarObjetivo(String(v))"
        >
            <SelectTrigger class="w-64"
                ><SelectValue placeholder="Selecciona una opción"
            /></SelectTrigger>
            <SelectContent>
                <SelectItem
                    v-for="opcion in opcionesObjetivo"
                    :key="opcion.id"
                    :value="String(opcion.id)"
                >
                    {{ opcion.etiqueta }}
                </SelectItem>
            </SelectContent>
        </Select>

        <Button
            type="button"
            variant="ghost"
            size="icon"
            title="Quitar destino"
            @click="emit('eliminar')"
        >
            <Trash2 class="size-4 text-destructive" />
        </Button>
    </div>
</template>
