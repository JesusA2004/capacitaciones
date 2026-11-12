<script setup lang="ts">
import CampoSolicitudControl from '@/components/incorporacion/CampoSolicitudControl.vue';
import { Label } from '@/components/ui/label';
import type { CampoListaSolicitud, ValorSolicitud } from '@/types';

/**
 * Un renglón de una lista de la solicitud (dependientes, empleos
 * anteriores, referencias…): un control por columna con su etiqueta.
 * Emite el renglón completo con el dato cambiado; no guarda nada.
 */
const props = defineProps<{
    campos: CampoListaSolicitud[];
    modelValue: Record<string, ValorSolicitud>;
    idBase: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [fila: Record<string, ValorSolicitud>];
}>();

function cambiar(campo: string, valor: ValorSolicitud): void {
    emit('update:modelValue', { ...props.modelValue, [campo]: valor });
}

function ancho(c: CampoListaSolicitud): boolean {
    return (
        c.tipo === 'texto_largo' ||
        c.tipo === 'multiselect' ||
        c.tipo === 'booleano' ||
        (c.tipo === 'select' && c.opciones.length <= 4)
    );
}
</script>

<template>
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <div
            v-for="c in campos"
            :key="c.campo"
            class="flex min-w-0 flex-col gap-1.5"
            :class="{ 'sm:col-span-2 lg:col-span-3': ancho(c) }"
        >
            <Label :for="`${idBase}-${c.campo}`" class="text-xs"
                >{{ c.etiqueta
                }}<span v-if="c.requerido" class="text-destructive">
                    *</span
                ></Label
            >
            <CampoSolicitudControl
                :id="`${idBase}-${c.campo}`"
                :campo="c"
                :model-value="modelValue[c.campo] ?? null"
                @update:model-value="(v) => cambiar(c.campo, v)"
            />
        </div>
    </div>
</template>
