<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Combobox } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { store, update } from '@/routes/administracion/usuarios';
import { show as verExpediente } from '@/routes/rh/expedientes';
import type { ColaboradorSinCuenta, UsuarioItem } from '@/types';

const props = defineProps<{
    open: boolean;
    usuario?: UsuarioItem | null;
    colaboradoresSinCuenta: ColaboradorSinCuenta[];
    rolesDisponibles: string[];
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
}>();

const opcionesColaborador = computed(() =>
    props.colaboradoresSinCuenta.map((colaborador) => ({
        value: String(colaborador.id),
        label: `${colaborador.numero_empleado ? `${colaborador.numero_empleado} — ` : ''}${colaborador.name} ${colaborador.apellidos ?? ''}`.trim(),
    })),
);

const form = useForm({
    colaborador_id: '',
    email: props.usuario?.email ?? '',
    zona_horaria: props.usuario?.zona_horaria ?? '',
    roles: [...(props.usuario?.roles?.map((rol) => rol.name) ?? [])] as string[],
});

function alternarEnLista(
    lista: string[],
    valor: string,
    marcado: boolean,
): string[] {
    return marcado
        ? [...new Set([...lista, valor])]
        : lista.filter((item) => item !== valor);
}

function enviar() {
    const opciones = {
        preserveScroll: true,
        onSuccess: () => emit('update:open', false),
    };

    if (props.usuario) {
        form.transform((datos) => ({
            email: datos.email,
            zona_horaria: datos.zona_horaria || null,
            roles: datos.roles,
        })).put(update.url(props.usuario.id), opciones);
    } else {
        form.transform((datos) => ({
            colaborador_id: datos.colaborador_id || null,
            email: datos.email,
            roles: datos.roles,
        })).post(store.url(), opciones);
    }
}
</script>

<template>
    <Sheet :open="open" @update:open="(valor) => emit('update:open', valor)">
        <SheetContent class="w-full overflow-y-auto sm:max-w-lg">
            <SheetHeader>
                <SheetTitle>{{
                    usuario ? 'Editar usuario' : 'Nuevo usuario'
                }}</SheetTitle>
            </SheetHeader>

            <form class="grid gap-4 px-4 pb-6" @submit.prevent="enviar">
                <template v-if="!usuario">
                    <div class="grid gap-2">
                        <Label>Colaborador</Label>
                        <Combobox
                            v-model="form.colaborador_id"
                            :items="opcionesColaborador"
                            placeholder="Busca por nombre o número de empleado..."
                            empty-text="No hay colaboradores sin cuenta."
                        />
                        <InputError :message="form.errors.colaborador_id" />
                        <p class="text-xs text-muted-foreground">
                            Solo aparecen colaboradores que todavía no tienen
                            cuenta de acceso.
                        </p>
                    </div>
                </template>

                <template v-else>
                    <div
                        class="grid gap-1 rounded-md border bg-muted/40 p-3 text-sm"
                    >
                        <p class="font-medium">
                            {{ usuario.colaborador?.name }}
                            {{ usuario.colaborador?.apellidos }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            No. empleado:
                            {{ usuario.colaborador?.numero_empleado ?? '—' }}
                            · Sucursal:
                            {{
                                usuario.colaborador?.sucursal_principal
                                    ?.nombre ?? 'Sin asignar'
                            }}
                            · Puesto:
                            {{ usuario.colaborador?.puesto?.nombre ?? 'Sin asignar' }}
                        </p>
                        <a
                            v-if="usuario.colaborador"
                            :href="verExpediente.url(usuario.colaborador.id)"
                            class="text-xs text-primary underline underline-offset-2"
                        >
                            Ver expediente del colaborador
                        </a>
                    </div>
                </template>

                <div class="grid gap-2">
                    <Label for="email">Correo de acceso</Label>
                    <Input id="email" v-model="form.email" type="email" autofocus />
                    <InputError :message="form.errors.email" />
                </div>

                <div v-if="usuario" class="grid gap-2">
                    <Label for="zona_horaria">Zona horaria</Label>
                    <Input
                        id="zona_horaria"
                        v-model="form.zona_horaria"
                        placeholder="America/Mexico_City"
                    />
                    <InputError :message="form.errors.zona_horaria" />
                </div>

                <div class="grid gap-2">
                    <Label>Roles</Label>
                    <div
                        class="grid max-h-40 grid-cols-2 gap-2 overflow-y-auto rounded-md border p-2"
                    >
                        <label
                            v-for="rol in rolesDisponibles"
                            :key="rol"
                            class="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                :model-value="form.roles.includes(rol)"
                                @update:model-value="
                                    (v) =>
                                        (form.roles = alternarEnLista(
                                            form.roles,
                                            rol,
                                            !!v,
                                        ))
                                "
                            />
                            {{ rol }}
                        </label>
                    </div>
                    <InputError :message="form.errors.roles" />
                </div>

                <p v-if="!usuario" class="text-sm text-muted-foreground">
                    Se enviará un correo al colaborador para que establezca su
                    propia contraseña.
                </p>

                <SheetFooter class="px-0">
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Guardar
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="emit('update:open', false)"
                        >Cancelar</Button
                    >
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
