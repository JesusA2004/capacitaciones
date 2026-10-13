<script setup lang="ts">
import { computed } from 'vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { columnaDatoFaltante } from '@/lib/datosFaltantes';
import type { DatoFaltante } from '@/types';

/**
 * Captura de los datos que le faltan a un documento: un control por dato
 * (texto, fecha, hora, correo, select), con dónde se guarda. Lo que no se
 * captura desde un documento (puesto, sucursal, contrato…) se lista aparte
 * para completarlo en su módulo. Lo usan la generación real y la vista
 * previa del editor de formatos.
 */
const props = defineProps<{ faltantes: DatoFaltante[] }>();
const valores = defineModel<Record<string, string>>({ required: true });

const editables = computed(() => props.faltantes.filter((f) => f.editable));
const noEditables = computed(() => props.faltantes.filter((f) => !f.editable));

function tipoInput(f: DatoFaltante): string {
    return f.control === 'fecha' ? 'date' : f.control === 'hora' ? 'time' : f.control === 'correo' ? 'email' : 'text';
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-for="f in editables" :key="columnaDatoFaltante(f)" class="flex flex-col gap-1">
            <Label :for="`falta-${f.campo}`">{{ f.etiqueta }}</Label>
            <Select v-if="f.control === 'select' && f.opciones?.length" v-model="valores[columnaDatoFaltante(f)]">
                <SelectTrigger :id="`falta-${f.campo}`"><SelectValue placeholder="Selecciona…" /></SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="o in f.opciones" :key="o.value" :value="o.value">{{ o.label }}</SelectItem>
                </SelectContent>
            </Select>
            <template v-else>
                <Input
                    :id="`falta-${f.campo}`"
                    v-model="valores[columnaDatoFaltante(f)]"
                    :type="tipoInput(f)"
                    :list="f.sugerencias?.length ? `sugerencias-${f.campo}` : undefined"
                    :inputmode="f.control === 'moneda' ? 'decimal' : undefined"
                />
                <datalist v-if="f.sugerencias?.length" :id="`sugerencias-${f.campo}`">
                    <option v-for="s in f.sugerencias" :key="s" :value="s" />
                </datalist>
            </template>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                {{
                    f.persistencia === 'documento'
                        ? 'Solo para este documento.'
                        : f.persistencia === 'sucursal'
                          ? 'Se guarda en la sucursal.'
                          : 'Se guarda en la ficha del colaborador.'
                }}
                <template v-if="f.documentos?.length"> Lo piden: {{ f.documentos.join(', ') }}.</template>
            </p>
        </div>
        <div v-if="noEditables.length" class="rounded-xl bg-[var(--mrl-fondo)] p-3 text-xs">
            <p class="font-medium">Se completan en su módulo (no desde el documento):</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="f in noEditables" :key="columnaDatoFaltante(f)">{{ f.etiqueta }}</li>
            </ul>
        </div>
    </div>
</template>
