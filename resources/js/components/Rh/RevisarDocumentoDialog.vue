<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { rechazar, solicitarCorreccion } from '@/routes/rh/documentos';

const props = defineProps<{
    open: boolean;
    documentoId: number;
    modo: 'rechazar' | 'corregir';
    tipoNombre: string;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    motivo: '',
});

const erroresGenericos = () => form.errors as Record<string, string>;

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('update:open', false);
        },
    };

    if (props.modo === 'rechazar') {
        form.transform((datos) => ({ rejection_reason: datos.motivo })).post(
            rechazar.url(props.documentoId),
            opciones,
        );
    } else {
        form.transform((datos) => ({ comments: datos.motivo })).post(
            solicitarCorreccion.url(props.documentoId),
            opciones,
        );
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>{{
                    modo === 'rechazar'
                        ? `Rechazar «${tipoNombre}»`
                        : `Pedir corrección de «${tipoNombre}»`
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="motivo">{{
                        modo === 'rechazar'
                            ? 'Motivo del rechazo'
                            : 'Qué debe corregir'
                    }}</Label>
                    <Textarea
                        id="motivo"
                        v-model="form.motivo"
                        rows="3"
                        autofocus
                        placeholder="Explica al colaborador qué debe corregir o por qué se rechaza..."
                    />
                    <InputError
                        :message="
                            erroresGenericos().rejection_reason ??
                            erroresGenericos().comments
                        "
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
                        type="submit"
                        variant="destructive"
                        :disabled="form.processing"
                    >
                        <Spinner v-if="form.processing" />
                        {{
                            modo === 'rechazar'
                                ? 'Rechazar'
                                : 'Pedir corrección'
                        }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
