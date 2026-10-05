<script setup lang="ts">
import { Check, ClipboardCopy, Copy, KeyRound, ShieldAlert } from '@lucide/vue';
import { ref } from 'vue';
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
import { useAlertas } from '@/composables/useAlertas';
import { leerCookie } from '@/lib/http';
import { credenciales } from '@/routes/rh/colaboradores';

/**
 * «Generar credenciales» (docs/AUTENTICACION.md): crea la cuenta si no
 * existe o le da una contraseña temporal nueva. Nunca pide correo. El
 * usuario es primer nombre + primer apellido; la contraseña temporal solo
 * se muestra aquí, una vez, para copiarla y entregarla en privado.
 */
const props = defineProps<{
    open: boolean;
    colaboradorId: number;
    colaboradorNombre: string;
    tieneCuenta: boolean;
}>();

const emit = defineEmits<{
    'update:open': [valor: boolean];
    generadas: [usuario: string];
}>();

type Credenciales = {
    usuario: string;
    contrasena: string;
    cuenta_nueva: boolean;
    correo: string | null;
};

const { mostrarExito, mostrarError } = useAlertas();
const generando = ref(false);
const resultado = ref<Credenciales | null>(null);
const copiado = ref<'usuario' | 'contrasena' | 'ambos' | null>(null);

async function generar() {
    generando.value = true;

    try {
        const respuesta = await fetch(credenciales.url(props.colaboradorId), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            credentials: 'same-origin',
            body: '{}',
        });
        const cuerpo = (await respuesta.json().catch(() => ({}))) as {
            data?: Credenciales;
            message?: string;
        };

        if (!respuesta.ok || !cuerpo.data) {
            mostrarError(
                respuesta.status === 403
                    ? 'No tienes permiso para generar credenciales de este colaborador.'
                    : (cuerpo.message ??
                          'No se pudieron generar las credenciales.'),
            );

            return;
        }

        resultado.value = cuerpo.data;
        emit('generadas', cuerpo.data.usuario);
    } catch {
        mostrarError('No se pudieron generar las credenciales.');
    } finally {
        generando.value = false;
    }
}

async function copiar(que: 'usuario' | 'contrasena' | 'ambos') {
    if (!resultado.value) {
        return;
    }

    const texto =
        que === 'usuario'
            ? resultado.value.usuario
            : que === 'contrasena'
              ? resultado.value.contrasena
              : `Usuario: ${resultado.value.usuario}\nContraseña: ${resultado.value.contrasena}`;

    try {
        await navigator.clipboard.writeText(texto);
        copiado.value = que;
        mostrarExito(
            que === 'ambos'
                ? 'Usuario y contraseña copiados.'
                : que === 'usuario'
                  ? 'Usuario copiado.'
                  : 'Contraseña copiada.',
        );
    } catch {
        mostrarError('No se pudo copiar; selecciónalo y cópialo a mano.');
    }
}

function cerrar() {
    resultado.value = null;
    copiado.value = null;
    emit('update:open', false);
}
</script>

<template>
    <Dialog
        :open="open"
        @update:open="(v) => (v ? emit('update:open', v) : cerrar())"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <KeyRound class="size-4 text-primary" />
                    Generar credenciales
                </DialogTitle>
                <DialogDescription>
                    Para {{ colaboradorNombre }}. Usuario = primer nombre +
                    primer apellido; no se necesita correo.
                </DialogDescription>
            </DialogHeader>

            <template v-if="!resultado">
                <p class="text-sm text-muted-foreground">
                    <template v-if="tieneCuenta">
                        Ya tiene cuenta: conserva su usuario y se le genera una
                        contraseña temporal nueva (la anterior deja de
                        funcionar).
                    </template>
                    <template v-else>
                        Se crea su cuenta de acceso con una contraseña temporal.
                    </template>
                    Al entrar por primera vez se le pedirá cambiarla.
                </p>

                <DialogFooter>
                    <Button variant="outline" @click="cerrar">Cancelar</Button>
                    <Button
                        :disabled="generando"
                        data-test="generar-credenciales"
                        @click="generar"
                    >
                        <Spinner v-if="generando" />
                        <KeyRound v-else class="size-4" />
                        Generar credenciales
                    </Button>
                </DialogFooter>
            </template>

            <template v-else>
                <div
                    class="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-xs text-warning"
                >
                    <ShieldAlert class="size-4 shrink-0" />
                    Cópialas ahora y entrégalas en privado: la contraseña no se
                    volverá a mostrar.
                </div>

                <div class="grid gap-2">
                    <Label for="cred-usuario">Usuario</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="cred-usuario"
                            :model-value="resultado.usuario"
                            readonly
                            class="font-medium"
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Copiar usuario"
                            @click="copiar('usuario')"
                        >
                            <Check
                                v-if="copiado === 'usuario'"
                                class="size-4"
                            />
                            <Copy v-else class="size-4" />
                        </Button>
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="cred-contrasena">Contraseña temporal</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="cred-contrasena"
                            :model-value="resultado.contrasena"
                            readonly
                            class="font-mono"
                        />
                        <Button
                            variant="outline"
                            size="icon"
                            aria-label="Copiar contraseña"
                            @click="copiar('contrasena')"
                        >
                            <Check
                                v-if="copiado === 'contrasena'"
                                class="size-4"
                            />
                            <Copy v-else class="size-4" />
                        </Button>
                    </div>
                </div>

                <DialogFooter class="gap-2 sm:justify-between">
                    <Button variant="outline" @click="copiar('ambos')">
                        <ClipboardCopy class="size-4" />
                        Copiar usuario y contraseña
                    </Button>
                    <Button @click="cerrar">Listo</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>
</template>
