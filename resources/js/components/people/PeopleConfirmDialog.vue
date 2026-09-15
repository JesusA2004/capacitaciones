<script setup lang="ts">
import { AlertTriangle } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import type { ButtonVariants } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';

/**
 * Reemplazo de `window.confirm()`/`confirm()` nativo en todo el portal: un
 * `confirm()` del navegador bloquea el hilo, no se puede estilizar, no
 * respeta dark mode y no permite pedir un motivo (rechazo, corrección,
 * eliminación con contexto). Este componente es la única forma aprobada de
 * pedir confirmación de una acción destructiva o irreversible.
 *
 * Uso típico: mantener un `ref` con el elemento pendiente de confirmar,
 * abrir el diálogo al pulsar la acción, y solo ejecutar la mutación real en
 * `@confirm`.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        titulo: string;
        descripcion?: string;
        /** Si se define, el diálogo pide un comentario/motivo obligatorio antes de confirmar (rechazo, corrección, etc.). */
        pedirComentario?: boolean;
        comentarioLabel?: string;
        comentarioPlaceholder?: string;
        comentarioModelValue?: string;
        destructivo?: boolean;
        cargando?: boolean;
        textoConfirmar?: string;
        textoCancelar?: string;
    }>(),
    {
        descripcion: undefined,
        pedirComentario: false,
        comentarioLabel: 'Comentario',
        comentarioPlaceholder: 'Explica el motivo…',
        comentarioModelValue: '',
        destructivo: false,
        cargando: false,
        textoConfirmar: 'Confirmar',
        textoCancelar: 'Cancelar',
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:comentarioModelValue': [value: string];
    confirm: [];
    cancel: [];
}>();

const varianteConfirmar = computed<NonNullable<ButtonVariants['variant']>>(
    () => (props.destructivo ? 'destructive' : 'default'),
);

const comentarioValido = computed(
    () => !props.pedirComentario || props.comentarioModelValue.trim() !== '',
);

function alCambiarAbierto(valor: boolean) {
    emit('update:open', valor);

    if (!valor) {
        emit('cancel');
    }
}

function cancelar() {
    emit('update:open', false);
    emit('cancel');
}

function confirmar() {
    if (!comentarioValido.value || props.cargando) {
        return;
    }

    emit('confirm');
}
</script>

<template>
    <Dialog :open="open" @update:open="alCambiarAbierto">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <AlertTriangle
                        v-if="destructivo"
                        class="size-5 text-destructive"
                    />
                    {{ titulo }}
                </DialogTitle>
                <DialogDescription v-if="descripcion">
                    {{ descripcion }}
                </DialogDescription>
            </DialogHeader>

            <div v-if="pedirComentario" class="flex flex-col gap-1.5">
                <Label for="people-confirm-dialog-comentario">{{
                    comentarioLabel
                }}</Label>
                <Textarea
                    id="people-confirm-dialog-comentario"
                    :model-value="comentarioModelValue"
                    :placeholder="comentarioPlaceholder"
                    rows="3"
                    @update:model-value="
                        (v) => emit('update:comentarioModelValue', String(v))
                    "
                />
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    :disabled="cargando"
                    @click="cancelar"
                >
                    {{ textoCancelar }}
                </Button>
                <Button
                    :variant="varianteConfirmar"
                    :disabled="!comentarioValido || cargando"
                    @click="confirmar"
                >
                    <Spinner v-if="cargando" class="size-4" />
                    {{ textoConfirmar }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
