<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
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
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/cursos';

defineProps<{
    open: boolean;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    titulo: '',
    descripcion: '',
    objetivo: '',
});

function enviar() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Nuevo curso</DialogTitle>
                <DialogDescription
                    >Después de crearlo podrás agregar módulos y lecciones en el
                    constructor.</DialogDescription
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="titulo">Título</Label>
                    <Input id="titulo" v-model="form.titulo" autofocus />
                    <InputError :message="form.errors.titulo" />
                </div>

                <div class="grid gap-2">
                    <Label for="objetivo">Objetivo</Label>
                    <Textarea id="objetivo" v-model="form.objetivo" rows="2" />
                    <InputError :message="form.errors.objetivo" />
                </div>

                <div class="grid gap-2">
                    <Label for="descripcion">Descripción</Label>
                    <Textarea
                        id="descripcion"
                        v-model="form.descripcion"
                        rows="3"
                    />
                    <InputError :message="form.errors.descripcion" />
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
                        Crear curso
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
