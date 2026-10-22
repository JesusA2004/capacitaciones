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
import {
    NativeSelect,
    NativeSelectOption,
} from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import type { RolItem } from '@/types/roles';

/**
 * Nuevo rol: nombre y desde qué rol parte (o vacío). Los permisos se
 * ajustan después en la matriz.
 */
const props = defineProps<{
    open: boolean;
    roles: RolItem[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
    creado: [nombre: string];
}>();

const form = useForm({
    nombre: '',
    base: '' as string,
    permisos: [] as string[],
});

function enviar() {
    const base = props.roles.find((r) => String(r.id) === form.base);

    form.transform((datos) => ({
        nombre: datos.nombre,
        permisos: base ? [...base.permisos] : [],
    })).post(RolController.store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            emit('creado', form.nombre);
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
                <DialogTitle>Nuevo rol</DialogTitle>
                <DialogDescription>
                    Ponle nombre y elige si parte de los permisos de otro rol.
                    Después ajustas cada acción en la matriz.
                </DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre_rol_nuevo">Nombre del rol</Label>
                    <Input
                        id="nombre_rol_nuevo"
                        v-model="form.nombre"
                        placeholder="por ejemplo: gerente_entrevistas"
                        autofocus
                    />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div class="grid gap-2">
                    <Label for="base_rol_nuevo">Partir de</Label>
                    <NativeSelect
                        id="base_rol_nuevo"
                        v-model="form.base"
                        class="w-full"
                    >
                        <NativeSelectOption value=""
                            >Sin permisos (vacío)</NativeSelectOption
                        >
                        <NativeSelectOption
                            v-for="rol in roles"
                            :key="rol.id"
                            :value="String(rol.id)"
                            >{{ rol.nombre }} ({{
                                rol.permisos.length
                            }}
                            permisos)</NativeSelectOption
                        >
                    </NativeSelect>
                    <InputError :message="form.errors.permisos" />
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
                        Crear rol
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
