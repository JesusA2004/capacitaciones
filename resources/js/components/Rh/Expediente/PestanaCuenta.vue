<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    KeyRound,
    Lock,
    Mail,
    Pencil,
    ShieldCheck,
    Unlock,
    UserCog,
    UserPlus,
} from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import CampoInfo from '@/components/Rh/CampoInfo.vue';
import GenerarCredencialesDialog from '@/components/Rh/GenerarCredencialesDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { update as actualizarCuentaUsuario } from '@/routes/administracion/usuarios';
import { edit as editSeguridad } from '@/routes/security';
import type { ExpedienteColaborador } from '@/types';

const props = defineProps<{
    colaborador: ExpedienteColaborador;
    esCuentaPropia: boolean;
    esPropio: boolean;
    puedeCrearCuenta: boolean;
    puedeEditarCuenta: boolean;
    puedeGestionarAcceso: boolean;
    puedeGestionarPassword: boolean;
    rolesDisponibles: string[];
    ocupado: boolean;
}>();

const emit = defineEmits<{
    'restablecer-acceso': [];
    'revocar-acceso': [];
}>();

const { mostrarExito, mostrarError } = useAlertas();

const dialogoPasswordAbierto = ref(false);

function alternarRol(lista: string[], rol: string, marcado: boolean): string[] {
    return marcado
        ? [...new Set([...lista, rol])]
        : lista.filter((r) => r !== rol);
}

const editandoCuenta = ref(false);

const formCuenta = useForm({
    email: props.colaborador.email ?? '',
    roles: [...props.colaborador.roles] as string[],
});

function iniciarEdicionCuenta() {
    formCuenta.email = props.colaborador.email ?? '';
    formCuenta.roles = [...props.colaborador.roles];
    editandoCuenta.value = true;
}

function guardarCuenta() {
    if (props.colaborador.usuario_id === null) {
        return;
    }

    formCuenta
        .transform((datos) => ({ email: datos.email, roles: datos.roles }))
        .put(actualizarCuentaUsuario.url(props.colaborador.usuario_id), {
            preserveScroll: true,
            onSuccess: () => {
                mostrarExito('Cuenta actualizada correctamente.');
                editandoCuenta.value = false;
            },
            onError: () => mostrarError('No se pudo actualizar la cuenta.'),
        });
}
</script>

<template>
    <div v-if="!colaborador.tiene_cuenta" class="grid grid-cols-1 gap-4">
        <Card v-if="puedeCrearCuenta" class="rounded-2xl border-border/60">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <UserPlus class="size-4" />
                    Crear cuenta de acceso
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div class="flex flex-col gap-3">
                    <p class="text-sm text-muted-foreground">
                        Usuario = primer nombre + primer apellido; no se
                        necesita correo. Se crea la cuenta con una contraseña
                        temporal que copias y entregas en privado.
                    </p>
                    <Button
                        class="w-fit"
                        data-test="abrir-generar-credenciales"
                        @click="dialogoPasswordAbierto = true"
                    >
                        <KeyRound class="size-4" />
                        Generar credenciales
                    </Button>
                </div>
            </CardContent>
        </Card>
        <p v-else class="text-sm text-muted-foreground">
            Este colaborador todavía no tiene cuenta de acceso y no tienes
            permiso para crear una.
        </p>
    </div>

    <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card class="rounded-2xl border-border/60">
            <CardHeader
                class="flex flex-row items-center justify-between gap-2"
            >
                <CardTitle class="flex items-center gap-2 text-base">
                    <UserCog class="size-4" />
                    Cuenta de acceso
                </CardTitle>
                <Button
                    v-if="puedeEditarCuenta && !editandoCuenta"
                    size="sm"
                    variant="ghost"
                    @click="iniciarEdicionCuenta"
                >
                    <Pencil class="size-3.5" />
                    Editar
                </Button>
            </CardHeader>
            <CardContent class="grid gap-4 text-sm">
                <template v-if="editandoCuenta">
                    <form
                        class="flex flex-col gap-4"
                        @submit.prevent="guardarCuenta"
                    >
                        <div class="grid gap-2">
                            <Label for="cuenta-email">Correo (opcional)</Label>
                            <Input
                                id="cuenta-email"
                                v-model="formCuenta.email"
                                type="email"
                            />
                            <InputError :message="formCuenta.errors.email" />
                        </div>
                        <div class="grid gap-2">
                            <Label>Roles</Label>
                            <div
                                class="grid max-h-40 grid-cols-2 gap-2 overflow-y-auto rounded-lg border p-2"
                            >
                                <label
                                    v-for="rol in rolesDisponibles"
                                    :key="rol"
                                    class="flex items-center gap-2 text-sm capitalize"
                                >
                                    <Checkbox
                                        :model-value="
                                            formCuenta.roles.includes(rol)
                                        "
                                        @update:model-value="
                                            (v) =>
                                                (formCuenta.roles = alternarRol(
                                                    formCuenta.roles,
                                                    rol,
                                                    !!v,
                                                ))
                                        "
                                    />
                                    {{ rol.replace(/_/g, ' ') }}
                                </label>
                            </div>
                            <InputError :message="formCuenta.errors.roles" />
                        </div>
                        <div class="flex gap-2">
                            <Button
                                type="submit"
                                size="sm"
                                :disabled="formCuenta.processing"
                            >
                                <Spinner v-if="formCuenta.processing" />
                                Guardar
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                @click="editandoCuenta = false"
                            >
                                Cancelar
                            </Button>
                        </div>
                    </form>
                </template>
                <template v-else>
                    <CampoInfo
                        :icono="Mail"
                        etiqueta="Correo (usuario de acceso)"
                        :valor="colaborador.email"
                    />
                    <div>
                        <p class="text-xs text-muted-foreground">Roles</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <Badge
                                v-for="rol in colaborador.roles"
                                :key="rol"
                                variant="outline"
                                class="capitalize"
                            >
                                {{ rol.replace(/_/g, ' ') }}
                            </Badge>
                            <span
                                v-if="colaborador.roles.length === 0"
                                class="text-xs text-muted-foreground"
                                >Sin roles asignados</span
                            >
                        </div>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">
                            Acceso al sistema
                        </p>
                        <p
                            class="flex items-center gap-1.5 font-medium"
                            :class="
                                colaborador.acceso_bloqueado_en
                                    ? 'text-destructive'
                                    : 'text-[var(--success)]'
                            "
                        >
                            <Lock
                                v-if="colaborador.acceso_bloqueado_en"
                                class="size-3.5"
                            />
                            <ShieldCheck v-else class="size-3.5" />
                            {{
                                colaborador.acceso_bloqueado_en
                                    ? 'Bloqueado'
                                    : 'Activo'
                            }}
                        </p>
                    </div>

                    <div
                        v-if="puedeGestionarAcceso"
                        class="flex flex-wrap gap-2 border-t pt-3"
                    >
                        <Button
                            v-if="colaborador.acceso_bloqueado_en"
                            size="sm"
                            variant="success"
                            @click="emit('restablecer-acceso')"
                        >
                            <Unlock class="size-3.5" />
                            Restablecer acceso
                        </Button>
                        <Button
                            :disabled="ocupado"
                            v-else
                            size="sm"
                            variant="outline"
                            @click="emit('revocar-acceso')"
                        >
                            <Lock class="size-3.5" />
                            Quitar acceso
                        </Button>
                    </div>
                </template>
            </CardContent>
        </Card>

        <Card class="rounded-2xl border-border/60">
            <CardHeader>
                <CardTitle class="flex items-center gap-2 text-base">
                    <KeyRound class="size-4" />
                    Contraseña
                </CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-3 text-sm">
                <p class="text-muted-foreground">
                    Por seguridad, la contraseña se guarda cifrada y no se puede
                    consultar la actual — ni un administrador puede leerla. Solo
                    puedes establecer una nueva.
                </p>
                <Button
                    v-if="puedeGestionarPassword"
                    size="sm"
                    class="w-fit"
                    @click="dialogoPasswordAbierto = true"
                >
                    <KeyRound class="size-3.5" />
                    Generar credenciales
                </Button>
                <p
                    v-else-if="esCuentaPropia"
                    class="text-xs text-muted-foreground"
                >
                    Es tu propia cuenta: no puedes restablecerte la contraseña
                    desde aquí. Usa
                    <Link
                        :href="editSeguridad.url()"
                        class="enlace-animado font-medium text-primary"
                        >Configuración → Seguridad</Link
                    >.
                </p>
                <p v-else class="text-xs text-muted-foreground">
                    No tienes permiso para cambiar la contraseña de este
                    colaborador.
                </p>
            </CardContent>
        </Card>
    </div>
    <GenerarCredencialesDialog
        v-if="dialogoPasswordAbierto"
        v-model:open="dialogoPasswordAbierto"
        :colaborador-id="colaborador.id"
        :colaborador-nombre="`${colaborador.name} ${colaborador.apellidos ?? ''}`"
        :tiene-cuenta="colaborador.tiene_cuenta"
        @generadas="router.reload()"
    />
</template>
