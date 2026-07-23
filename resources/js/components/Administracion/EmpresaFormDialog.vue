<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
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
import { store, update } from '@/routes/administracion/empresas';
import type { EmpresaItem } from '@/types';

const props = defineProps<{
    open: boolean;
    empresa?: EmpresaItem | null;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const form = useForm({
    nombre: props.empresa?.nombre ?? '',
    razon_social: props.empresa?.razon_social ?? '',
    rfc: props.empresa?.rfc ?? '',
    logo: null as File | null,
    activo: props.empresa?.activo ?? true,
});

function seleccionarLogo(evento: Event) {
    const input = evento.target as HTMLInputElement;
    form.logo = input.files?.[0] ?? null;
}

function enviar() {
    const opciones = {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.empresa) {
        form.post(update.url(props.empresa.id), opciones);
    } else {
        form.post(store.url(), opciones);
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(valor) => emit('update:open', valor)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    empresa ? 'Editar empresa' : 'Nueva empresa'
                }}</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid gap-2">
                    <Label for="nombre">Nombre comercial</Label>
                    <Input id="nombre" v-model="form.nombre" autofocus />
                    <InputError :message="form.errors.nombre" />
                </div>

                <div class="grid gap-2">
                    <Label for="razon_social">Razón social</Label>
                    <Input id="razon_social" v-model="form.razon_social" />
                    <InputError :message="form.errors.razon_social" />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="rfc">RFC</Label>
                        <Input
                            id="rfc"
                            v-model="form.rfc"
                            maxlength="13"
                            class="uppercase"
                        />
                        <InputError :message="form.errors.rfc" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="logo">Logo</Label>
                        <div class="flex items-center gap-2">
                            <span
                                v-if="empresa?.logo_url && !form.logo"
                                class="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-border/60 bg-muted"
                            >
                                <img
                                    :src="empresa.logo_url"
                                    alt=""
                                    class="size-full object-cover"
                                />
                            </span>
                            <span
                                v-else
                                class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                            >
                                <Building2 class="size-4" />
                            </span>
                            <Input
                                id="logo"
                                type="file"
                                accept="image/*"
                                class="text-xs"
                                @change="seleccionarLogo"
                            />
                        </div>
                        <InputError :message="form.errors.logo" />
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <Checkbox
                        :model-value="form.activo"
                        @update:model-value="(v) => (form.activo = !!v)"
                    />
                    Empresa activa
                </label>

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
