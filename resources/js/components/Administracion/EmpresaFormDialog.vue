<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Building2 } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
    domicilio_fiscal: props.empresa?.domicilio_fiscal ?? '',
    registro_patronal: props.empresa?.registro_patronal ?? '',
    codigo_postal_fiscal: props.empresa?.codigo_postal_fiscal ?? '',
    ciudad_firma: props.empresa?.ciudad_firma ?? '',
    representante_legal_nombre: props.empresa?.representante_legal_nombre ?? '',
    representante_legal_cargo: props.empresa?.representante_legal_cargo ?? '',
});

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
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-lg">
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
                            <PeopleFileDropzone
                                class="min-w-0 flex-1"
                                :model-value="form.logo ? [form.logo] : []"
                                accept=".png,.jpg,.jpeg,.webp,.svg"
                                :max-size-mb="5"
                                label="Arrastra el logo aquí"
                                @update:model-value="
                                    (f) => (form.logo = f[0] ?? null)
                                "
                            />
                        </div>
                        <InputError :message="form.errors.logo" />
                    </div>
                </div>

                <fieldset
                    class="grid gap-3 rounded-xl border border-border/60 p-3"
                >
                    <legend
                        class="px-1 text-xs font-semibold text-muted-foreground"
                    >
                        Datos del patrón en documentos laborales
                    </legend>
                    <p class="text-xs text-muted-foreground">
                        Se imprimen en contratos y convenios. Si quedan vacíos,
                        PEOPLE usa el valor predeterminado del registro jurídico
                        y lo advierte en la vista previa.
                    </p>
                    <div class="grid gap-2">
                        <Label for="representante_legal_nombre"
                            >Representante legal</Label
                        >
                        <Input
                            id="representante_legal_nombre"
                            v-model="form.representante_legal_nombre"
                        />
                        <InputError
                            :message="form.errors.representante_legal_nombre"
                        />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="representante_legal_cargo"
                                >Cargo del representante</Label
                            >
                            <Input
                                id="representante_legal_cargo"
                                v-model="form.representante_legal_cargo"
                                placeholder="Representante Legal"
                            />
                            <InputError
                                :message="form.errors.representante_legal_cargo"
                            />
                        </div>
                        <div class="grid gap-2">
                            <Label for="ciudad_firma">Ciudad de firma</Label>
                            <Input
                                id="ciudad_firma"
                                v-model="form.ciudad_firma"
                                placeholder="Cuernavaca, Morelos"
                            />
                            <InputError :message="form.errors.ciudad_firma" />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <Label for="domicilio_fiscal">Domicilio fiscal</Label>
                        <Input
                            id="domicilio_fiscal"
                            v-model="form.domicilio_fiscal"
                        />
                        <InputError :message="form.errors.domicilio_fiscal" />
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="registro_patronal">Registro patronal IMSS</Label>
                            <Input id="registro_patronal" v-model="form.registro_patronal" placeholder="D0000000000" />
                            <InputError :message="form.errors.registro_patronal" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="codigo_postal_fiscal">Lugar de expedición (C.P.)</Label>
                            <Input id="codigo_postal_fiscal" v-model="form.codigo_postal_fiscal" inputmode="numeric" placeholder="62260" />
                            <InputError :message="form.errors.codigo_postal_fiscal" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Aparecen en el encabezado de los formatos oficiales (recibo de nómina, finiquito y permiso).
                    </p>
                </fieldset>

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
