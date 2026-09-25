<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { update } from '@/routes/administracion/usuarios';
import type { UsuarioItem } from '@/types';

const props = defineProps<{
    open: boolean;
    usuario: UsuarioItem;
    rolesDisponibles: string[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    email: props.usuario.email,
    roles: [...props.usuario.roles_nombres],
});

function alternarRol(rol: string, marcado: boolean) {
    form.roles = marcado
        ? [...new Set([...form.roles, rol])]
        : form.roles.filter((r) => r !== rol);
}

function enviar() {
    form.put(update.url(props.usuario.id), {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle
                    >Editar cuenta — {{ usuario.name }}
                    {{ usuario.apellidos }}</DialogTitle
                >
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="editar-email">Correo de acceso</Label>
                    <Input
                        id="editar-email"
                        v-model="form.email"
                        type="email"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label>Roles</Label>
                    <div
                        class="grid max-h-40 grid-cols-2 gap-2 overflow-y-auto rounded-lg border p-2"
                    >
                        <label
                            v-for="rol in props.rolesDisponibles"
                            :key="rol"
                            class="flex items-center gap-2 text-sm capitalize"
                        >
                            <Checkbox
                                :model-value="form.roles.includes(rol)"
                                @update:model-value="
                                    (v) => alternarRol(rol, !!v)
                                "
                            />
                            {{ rol.replace(/_/g, ' ') }}
                        </label>
                    </div>
                    <InputError :message="form.errors.roles" />
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
                        Guardar
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
