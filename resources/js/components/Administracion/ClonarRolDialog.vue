<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import RolController from '@/actions/App/Http/Controllers/Administracion/RolController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { RolItem } from './RolFormDialog.vue';

const props = defineProps<{
    open: boolean;
    rol: RolItem | null;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: '',
});

function enviar() {
    if (!props.rol) {
        return;
    }

    form.post(RolController.clonar.url(props.rol.id), {
        preserveScroll: true,
        onSuccess: () => {
            emit('update:open', false);
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Clonar rol «{{ rol?.nombre }}»</DialogTitle>
                <DialogDescription>
                    Se creará un nuevo rol con los mismos permisos que «{{
                        rol?.nombre
                    }}».
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre_clon">Nombre del nuevo rol</Label>
                    <Input
                        id="nombre_clon"
                        v-model="form.nombre"
                        placeholder="por ejemplo: gerente_sucursal_norte"
                        autofocus
                    />
                    <InputError :message="form.errors.nombre" />
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
                        Clonar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
