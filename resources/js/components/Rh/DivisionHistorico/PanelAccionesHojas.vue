<script setup lang="ts">
import { CornerDownRight, MoveRight, Scissors, Tag, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
import type { OpcionBuscable } from '@/components/ui/combobox-buscable';
import { Input } from '@/components/ui/input';
import { formatearRango, parsearRango } from '@/lib/editorHojas';
import { opcionesDocumentoChecklist } from '@/lib/opcionesDocumentoChecklist';
import type { TipoEditor } from '@/types';

/**
 * Acciones sobre las hojas seleccionadas del editor. Se muestra como barra
 * fija arriba en escritorio y dentro de una hoja inferior en móvil: misma
 * lógica, distinto contenedor.
 */
const props = defineProps<{
    seleccion: number[];
    total: number;
    tipos: TipoEditor[];
    /** Documentos clasificados para «Mover a…». */
    destinos: { value: string; label: string }[];
    deshabilitado?: boolean;
}>();

const emit = defineEmits<{
    asignar: [tipoId: number | null, aparte: boolean];
    continuar: [];
    partir: [];
    mover: [destinoId: string];
    seleccionarRango: [paginas: number[]];
    limpiar: [];
}>();

/** -1 = «Sin clasificar» (el Select no admite null como opción). */
const SIN_CLASIFICAR = -1;

const tipoElegido = ref<number | null>(null);
const aparte = ref(false);
const destino = ref<string | null>(null);
const rango = ref('');
const errorRango = ref<string | null>(null);

const opcionesTipo = computed<OpcionBuscable[]>(() => [
    {
        value: SIN_CLASIFICAR,
        label: 'Sin clasificar (dejar fuera)',
        alias: ['ninguno', 'fuera', 'quitar'],
    },
    ...opcionesDocumentoChecklist(props.tipos),
]);

const hay = computed(() => props.seleccion.length > 0);

function aplicarRango(): void {
    const { paginas, error } = parsearRango(rango.value, props.total);
    errorRango.value = error;

    if (!error) {
        emit('seleccionarRango', paginas);
    }
}

function asignar(): void {
    if (tipoElegido.value === null) {
        return;
    }

    emit(
        'asignar',
        tipoElegido.value === SIN_CLASIFICAR ? null : tipoElegido.value,
        aparte.value,
    );
}

function mover(): void {
    if (destino.value) {
        emit('mover', destino.value);
        destino.value = null;
    }
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <form
            class="flex flex-wrap items-start gap-2"
            @submit.prevent="aplicarRango"
        >
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <label for="rango-hojas" class="text-xs font-medium"
                    >Seleccionar hojas</label
                >
                <Input
                    id="rango-hojas"
                    v-model="rango"
                    placeholder="p. ej. 5-7, 9"
                    inputmode="numeric"
                    autocomplete="off"
                    :aria-invalid="errorRango !== null"
                    aria-describedby="rango-ayuda"
                />
                <p
                    id="rango-ayuda"
                    class="text-xs"
                    :class="
                        errorRango
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                    aria-live="polite"
                >
                    {{
                        errorRango ??
                        'Clic, Mayús+clic para un rango, Ctrl/Cmd+clic para sumar.'
                    }}
                </p>
            </div>
            <Button type="submit" variant="outline" class="mt-5">Elegir</Button>
        </form>

        <div
            class="flex flex-wrap items-center gap-2 rounded-xl bg-crema/60 px-3 py-2 text-sm"
            aria-live="polite"
        >
            <span v-if="hay" class="font-medium">
                {{ seleccion.length }}
                {{
                    seleccion.length === 1
                        ? 'hoja seleccionada'
                        : 'hojas seleccionadas'
                }}:
                {{ formatearRango(seleccion) }}
            </span>
            <span v-else class="text-muted-foreground"
                >Ninguna hoja seleccionada.</span
            >
            <Button
                v-if="hay"
                size="sm"
                variant="ghost"
                class="ml-auto"
                @click="emit('limpiar')"
            >
                <X class="size-4" /> Quitar selección
            </Button>
        </div>

        <fieldset class="flex flex-col gap-2" :disabled="!hay || deshabilitado">
            <legend class="mb-1 text-xs font-medium">
                Asignar clasificación
            </legend>
            <div class="flex flex-wrap items-center gap-2">
                <ComboboxBuscable
                    v-model="tipoElegido"
                    :opciones="opcionesTipo"
                    placeholder="Documento del checklist…"
                    placeholder-busqueda="Busca: INE, CURP, licencia…"
                    numerico
                    class="w-full min-w-0 sm:w-72"
                    aria-label="Documento del checklist"
                    :disabled="!hay || deshabilitado"
                />
                <Button
                    :disabled="!hay || tipoElegido === null || deshabilitado"
                    @click="asignar"
                >
                    <Tag class="size-4" /> Asignar
                </Button>
            </div>
            <label
                class="flex items-center gap-2 text-xs text-muted-foreground"
            >
                <Checkbox
                    :model-value="aparte"
                    :disabled="!hay || deshabilitado"
                    @update:model-value="(v) => (aparte = v === true)"
                />
                Como documento aparte (si ya hay uno de ese tipo, no unirlas)
            </label>
        </fieldset>

        <div class="flex flex-wrap gap-2">
            <Button
                variant="outline"
                :disabled="!hay || deshabilitado"
                title="Une las hojas al documento de la hoja anterior (p. ej. 9-11 al contrato de la 8)"
                @click="emit('continuar')"
            >
                <CornerDownRight class="size-4" /> Continúa el documento
                anterior
            </Button>
            <Button
                variant="outline"
                :disabled="seleccion.length !== 1 || deshabilitado"
                title="Parte el documento: esta hoja y las siguientes forman uno nuevo"
                @click="emit('partir')"
            >
                <Scissors class="size-4" /> Nuevo documento desde esta hoja
            </Button>
        </div>

        <div v-if="destinos.length" class="flex flex-wrap items-center gap-2">
            <SelectSimple
                v-model="destino"
                :opciones="destinos"
                placeholder="Mover a otro documento…"
                class="w-full min-w-0 sm:w-72"
                aria-label="Documento destino"
                :disabled="!hay || deshabilitado"
            />
            <Button
                variant="outline"
                :disabled="!hay || !destino || deshabilitado"
                @click="mover"
            >
                <MoveRight class="size-4" /> Mover
            </Button>
        </div>
    </div>
</template>
