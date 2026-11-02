<script setup lang="ts">
import { Briefcase } from '@lucide/vue';
import PuestoDatosFormulario from '@/components/puestos/PuestoDatosFormulario.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { OpcionSimple, PuestoItem } from '@/types';

/**
 * «Nuevo puesto» (y edición rápida). Al crear, el backend lleva a la ficha
 * del puesto para capturar su tabulador y asignar a sus personas.
 */
defineProps<{
    open: boolean;
    puesto?: PuestoItem | null;
    departamentosDisponibles: OpcionSimple[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <div class="flex items-center gap-3">
                    <span
                        class="grid size-10 shrink-0 place-items-center rounded-2xl bg-salvia text-primary"
                    >
                        <Briefcase class="size-5" />
                    </span>
                    <div>
                        <DialogTitle>{{
                            puesto ? 'Editar puesto' : 'Nuevo puesto'
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                puesto
                                    ? 'Cambia los datos del catálogo.'
                                    : 'Después capturas su tabulador y asignas a sus personas.'
                            }}
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <PuestoDatosFormulario
                :puesto="puesto"
                :departamentos-disponibles="departamentosDisponibles"
                cancelable
                @guardado="emit('update:open', false)"
                @cancelar="emit('update:open', false)"
            />
        </DialogContent>
    </Dialog>
</template>
