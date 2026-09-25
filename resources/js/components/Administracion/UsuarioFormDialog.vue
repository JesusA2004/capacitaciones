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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/administracion/usuarios';

type ColaboradorOpcion = { id: number; name: string; apellidos: string | null };

const props = defineProps<{
    open: boolean;
    colaboradoresSinCuenta: ColaboradorOpcion[];
    rolesDisponibles: string[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    colaborador_id: '',
    email: '',
    roles: [] as string[],
});

function alternarRol(rol: string, marcado: boolean) {
    form.roles = marcado
        ? [...new Set([...form.roles, rol])]
        : form.roles.filter((r) => r !== rol);
}

function enviar() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('update:open', false);
        },
    });
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nuevo usuario</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label>Colaborador</Label>
                    <Select v-model="form.colaborador_id">
                        <SelectTrigger class="w-full">
                            <SelectValue
                                placeholder="Selecciona un colaborador sin cuenta"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in props.colaboradoresSinCuenta"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                            >
                                {{ opcion.name }} {{ opcion.apellidos }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.colaborador_id" />
                    <p
                        v-if="props.colaboradoresSinCuenta.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        Todos los colaboradores activos ya tienen cuenta de
                        acceso.
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="usuario-email">Correo de acceso</Label>
                    <Input
                        id="usuario-email"
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

                <p class="text-xs text-muted-foreground">
                    Se enviará un correo al colaborador para que establezca su
                    propia contraseña.
                </p>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                    <Button
                        type="submit"
                        :disabled="form.processing || !form.colaborador_id"
                    >
                        <Spinner v-if="form.processing" />
                        Crear usuario
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
