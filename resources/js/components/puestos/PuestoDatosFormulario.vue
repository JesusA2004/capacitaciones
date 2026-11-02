<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Loader2 } from '@lucide/vue';
import Casilla from '@/components/Common/Casilla.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { store, update } from '@/routes/administracion/puestos';
import type { OpcionSimple } from '@/types';

/**
 * Datos del catálogo de un puesto (nombre, departamento, descripción,
 * periodo de prueba, activo). Lo usan el diálogo «Nuevo puesto» y la
 * pestaña «Datos del puesto» de su ficha.
 */
const props = defineProps<{
    puesto?: {
        id: number;
        nombre: string;
        departamento_id: number | null;
        descripcion: string | null;
        activo: boolean;
        meses_periodo_prueba: number | null;
    } | null;
    departamentosDisponibles: OpcionSimple[];
    /** Muestra «Cancelar» (en el diálogo). */
    cancelable?: boolean;
}>();

const emit = defineEmits<{ guardado: []; cancelar: [] }>();

const form = useForm({
    nombre: props.puesto?.nombre ?? '',
    departamento_id: props.puesto?.departamento_id ?? (null as number | null),
    descripcion: props.puesto?.descripcion ?? '',
    activo: props.puesto?.activo ?? true,
    meses_periodo_prueba:
        props.puesto?.meses_periodo_prueba ?? (null as number | null),
});

const opcionesMeses = Array.from({ length: 12 }, (_, i) => ({
    value: i + 1,
    label: i === 0 ? '1 mes' : `${i + 1} meses`,
}));

function enviar(): void {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults();
            emit('guardado');
        },
    };

    if (props.puesto) {
        form.put(update.url(props.puesto.id), opciones);
    } else {
        form.post(store.url(), opciones);
    }
}
</script>

<template>
    <form class="grid gap-5" @submit.prevent="enviar">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="puesto-nombre">Nombre del puesto</Label>
                <Input
                    id="puesto-nombre"
                    v-model="form.nombre"
                    class="h-11 rounded-xl text-base"
                    placeholder="Ej. Gerente de Sucursal"
                    autofocus
                />
                <InputError :message="form.errors.nombre" />
            </div>

            <div class="grid gap-1.5">
                <Label>Departamento</Label>
                <SelectSimple
                    v-model="form.departamento_id"
                    :opciones="
                        departamentosDisponibles.map((o) => ({
                            value: o.id,
                            label: o.nombre,
                        }))
                    "
                    numerico
                    placeholder="Sin departamento"
                    opcion-vacia="Sin departamento"
                    placeholder-busqueda="Buscar departamento…"
                />
                <InputError :message="form.errors.departamento_id" />
            </div>

            <div class="grid gap-1.5">
                <Label>Periodo de prueba</Label>
                <SelectSimple
                    v-model="form.meses_periodo_prueba"
                    :opciones="opcionesMeses"
                    numerico
                    placeholder="Sin configurar"
                    opcion-vacia="Sin configurar"
                />
                <p class="text-[11px] leading-snug text-muted-foreground">
                    Contrato de capacitación inicial. Sin esta duración no se
                    calculan sus contratos ni su vencimiento.
                </p>
                <InputError :message="form.errors.meses_periodo_prueba" />
            </div>

            <div class="grid gap-1.5 sm:col-span-2">
                <Label for="puesto-descripcion">
                    Descripción
                    <span class="font-normal text-muted-foreground"
                        >(opcional)</span
                    >
                </Label>
                <Textarea
                    id="puesto-descripcion"
                    v-model="form.descripcion"
                    rows="3"
                    class="rounded-xl"
                    placeholder="Para qué existe el puesto, en una o dos líneas"
                />
                <InputError :message="form.errors.descripcion" />
            </div>
        </div>

        <label
            class="flex cursor-pointer items-start gap-3 rounded-2xl border bg-crema/30 p-3"
        >
            <Casilla
                :model-value="form.activo"
                class="mt-0.5"
                @update:model-value="(v) => (form.activo = v === true)"
            />
            <span class="text-sm">
                <span class="block font-medium">Puesto activo</span>
                <span class="text-xs text-muted-foreground"
                    >Un puesto inactivo no se ofrece en vacantes, altas ni
                    traslados; quien ya lo tiene lo conserva.</span
                >
            </span>
        </label>

        <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <Button
                v-if="cancelable"
                type="button"
                variant="ghost"
                @click="emit('cancelar')"
                >Cancelar</Button
            >
            <Button
                type="submit"
                :disabled="form.processing || (!!puesto && !form.isDirty)"
            >
                <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                <Check v-else class="size-4" />
                {{ puesto ? 'Guardar cambios' : 'Crear puesto' }}
            </Button>
        </div>
    </form>
</template>
