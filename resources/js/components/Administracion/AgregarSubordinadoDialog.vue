<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { actualizar } from '@/routes/administracion/jerarquia-puestos';
import type { PuestoJerarquiaItem } from '@/types';

const props = defineProps<{
    open: boolean;
    puesto: PuestoJerarquiaItem;
    todosLosPuestos: PuestoJerarquiaItem[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const { mostrarExito, mostrarError } = useAlertas();
const puestoElegidoId = ref('');

const opcionesPuesto = computed(() =>
    props.todosLosPuestos
        .filter((p) => p.id !== props.puesto.id)
        .map((p) => ({
            value: String(p.id),
            label: p.nombre,
            descripcion: p.puesto_superior
                ? `Hoy: ${p.puesto_superior.nombre}`
                : null,
        })),
);
const enviando = ref(false);

function enviar() {
    if (!puestoElegidoId.value) {
        return;
    }

    enviando.value = true;
    router.put(
        actualizar.url(Number(puestoElegidoId.value)),
        { puesto_superior_id: props.puesto.id },
        {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito(
                    `${props.puesto.nombre} ahora es superior de ese puesto.`,
                );
                puestoElegidoId.value = '';
                emit('update:open', false);
            },
            onError: () =>
                mostrarError(
                    'No se pudo asignar la relación. Revisa que no forme un ciclo.',
                ),
            onFinish: () => (enviando.value = false),
        },
    );
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle
                    >Agregar puesto debajo de «{{ puesto.nombre }}»</DialogTitle
                >
                <DialogDescription>
                    Elige un puesto existente que empezará a reportar a
                    {{ puesto.nombre }}. Para crear un puesto nuevo, ve a
                    Administración › Puestos primero.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label>Puesto a conectar</Label>
                <SelectSimple
                    v-model="puestoElegidoId"
                    :opciones="opcionesPuesto"
                    placeholder="Selecciona un puesto..."
                    placeholder-busqueda="Buscar puesto…"
                />
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    variant="secondary"
                    @click="emit('update:open', false)"
                    >Cancelar</Button
                >
                <Button
                    :disabled="!puestoElegidoId || enviando"
                    @click="enviar"
                >
                    <Spinner v-if="enviando" />
                    Conectar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
