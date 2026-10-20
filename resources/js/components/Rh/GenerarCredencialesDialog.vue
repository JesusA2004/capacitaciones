<script setup lang="ts">
import {
    Check,
    ClipboardCopy,
    Copy,
    Eye,
    EyeOff,
    FileSearch,
    KeyRound,
    Mail,
    ShieldAlert,
} from '@lucide/vue';
import { ref } from 'vue';
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
import { useAlertas } from '@/composables/useAlertas';
import { leerCookie } from '@/lib/http';
import { credenciales } from '@/routes/rh/colaboradores';
import {
    correo as correoCredenciales,
    correoVistaPrevia,
} from '@/routes/rh/colaboradores/credenciales';

/**
 * «Generar credenciales» (docs/AUTENTICACION.md): crea la cuenta si no
 * existe o le da una contraseña temporal nueva. RH elige entre generar una
 * contraseña segura o escribir una manual (misma política de contraseñas).
 * El usuario es primer nombre + primer apellido; la contraseña solo se
 * muestra aquí, una vez, y se puede enviar por correo si la cuenta lo tiene.
 * Nunca se guarda en texto plano ni se registra en el log.
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
const modo = ref<'generar' | 'manual'>('generar');
const manual = ref('');
const verManual = ref(false);
const errorPassword = ref<string | null>(null);
const enviando = ref(false);
const correoEnviado = ref(false);

async function pedir(url: string, cuerpo: Record<string, unknown>) {
    const respuesta = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
        },
        credentials: 'same-origin',
        body: JSON.stringify(cuerpo),
    });
    const datos = (await respuesta.json().catch(() => ({}))) as {
        data?: Credenciales;
        message?: string;
        errors?: Record<string, string[]>;
    };

    return { respuesta, datos };
}

async function generar() {
    if (generando.value) {
        return;
    }

    errorPassword.value = null;

    if (modo.value === 'manual' && manual.value.trim() === '') {
        errorPassword.value = 'Escribe la contraseña que quieres asignar.';

        return;
    }

    generando.value = true;

    try {
        const { respuesta, datos } = await pedir(
            credenciales.url(props.colaboradorId),
            modo.value === 'manual' ? { password: manual.value } : {},
        );

        if (respuesta.status === 422) {
            errorPassword.value =
                datos.errors?.password?.[0] ??
                datos.errors?.credenciales?.[0] ??
                datos.message ??
                'Revisa la contraseña.';
            mostrarError(errorPassword.value);

            return;
        }

        if (!respuesta.ok || !datos.data) {
            mostrarError(
                respuesta.status === 403
                    ? 'No tienes permiso para generar credenciales de este colaborador.'
                    : (datos.message ??
                          'No se pudieron generar las credenciales.'),
            );

            return;
        }

        resultado.value = datos.data;
        manual.value = '';
        emit('generadas', datos.data.usuario);
    } catch {
        mostrarError('No se pudieron generar las credenciales.');
    } finally {
        generando.value = false;
    }
}

async function enviarCorreo() {
    if (!resultado.value || enviando.value) {
        return;
    }

    enviando.value = true;

    try {
        const { respuesta, datos } = await pedir(
            correoCredenciales.url(props.colaboradorId),
            { password: resultado.value.contrasena },
        );

        if (respuesta.ok) {
            correoEnviado.value = true;
            mostrarExito(`Accesos enviados a ${resultado.value.correo}.`);

            return;
        }

        // La contraseña ya quedó actualizada: solo falló el correo.
        mostrarError(datos.message ?? 'No se pudo enviar el correo.');
    } catch {
        mostrarError('No se pudo enviar el correo.');
    } finally {
        enviando.value = false;
    }
}

// Vista previa del correo ANTES de enviarlo: el backend renderiza el mismo
// correo con la contraseña enmascarada (aquí nunca se manda la contraseña).
type VistaPreviaCorreo = { asunto: string; destinatario: string; html: string };
const vistaPreviaCorreo = ref<VistaPreviaCorreo | null>(null);
const vistaPreviaCorreoAbierta = ref(false);
const cargandoVistaPreviaCorreo = ref(false);

async function verVistaPreviaCorreo() {
    if (cargandoVistaPreviaCorreo.value) {
        return;
    }

    cargandoVistaPreviaCorreo.value = true;

    try {
        const respuesta = await fetch(
            correoVistaPrevia.url(props.colaboradorId),
            {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
                },
                credentials: 'same-origin',
                body: '{}',
            },
        );
        const datos = (await respuesta.json().catch(() => ({}))) as {
            data?: VistaPreviaCorreo;
            message?: string;
            errors?: Record<string, string[]>;
        };

        if (!respuesta.ok || !datos.data) {
            mostrarError(
                Object.values(datos.errors ?? {})[0]?.[0] ??
                    datos.message ??
                    'No se pudo preparar la vista previa del correo.',
            );

            return;
        }

        vistaPreviaCorreo.value = datos.data;
        vistaPreviaCorreoAbierta.value = true;
    } catch {
        mostrarError('No se pudo preparar la vista previa del correo.');
    } finally {
        cargandoVistaPreviaCorreo.value = false;
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
    manual.value = '';
    errorPassword.value = null;
    correoEnviado.value = false;
    vistaPreviaCorreo.value = null;
    vistaPreviaCorreoAbierta.value = false;
    modo.value = 'generar';
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
                    {{ tieneCuenta ? 'Contraseña de acceso' : 'Crear acceso' }}
                </DialogTitle>
                <DialogDescription>
                    Para {{ colaboradorNombre }}. Usuario = primer nombre +
                    primer apellido; no se necesita correo.
                </DialogDescription>
            </DialogHeader>

            <template v-if="!resultado">
                <p class="text-sm text-muted-foreground">
                    <template v-if="tieneCuenta">
                        Conserva su usuario; la contraseña anterior deja de
                        funcionar.
                    </template>
                    <template v-else>
                        Se crea su cuenta de acceso con una contraseña temporal.
                    </template>
                    Al entrar por primera vez se le pedirá cambiarla.
                </p>

                <div class="grid grid-cols-2 gap-2" role="radiogroup">
                    <Button
                        type="button"
                        :variant="modo === 'generar' ? 'default' : 'outline'"
                        role="radio"
                        :aria-checked="modo === 'generar'"
                        @click="modo = 'generar'"
                    >
                        Generar contraseña segura
                    </Button>
                    <Button
                        type="button"
                        :variant="modo === 'manual' ? 'default' : 'outline'"
                        role="radio"
                        :aria-checked="modo === 'manual'"
                        @click="modo = 'manual'"
                    >
                        Escribir contraseña manual
                    </Button>
                </div>

                <div v-if="modo === 'manual'" class="grid gap-2">
                    <Label for="cred-manual">Contraseña</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="cred-manual"
                            v-model="manual"
                            :type="verManual ? 'text' : 'password'"
                            autocomplete="new-password"
                            :aria-invalid="errorPassword !== null"
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            :aria-label="
                                verManual
                                    ? 'Ocultar contraseña'
                                    : 'Mostrar contraseña'
                            "
                            @click="verManual = !verManual"
                        >
                            <EyeOff v-if="verManual" class="size-4" />
                            <Eye v-else class="size-4" />
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Debe cumplir la política de contraseñas de PEOPLE
                        (longitud mínima, mayúsculas, números y símbolos).
                    </p>
                    <InputError :message="errorPassword ?? undefined" />
                </div>
                <InputError v-else :message="errorPassword ?? undefined" />

                <DialogFooter>
                    <Button variant="outline" @click="cerrar">Cancelar</Button>
                    <Button
                        :disabled="generando"
                        data-test="generar-credenciales"
                        @click="generar"
                    >
                        <Spinner v-if="generando" />
                        <KeyRound v-else class="size-4" />
                        {{
                            modo === 'manual'
                                ? 'Establecer contraseña'
                                : 'Generar credenciales'
                        }}
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

                <p
                    v-if="!resultado.correo"
                    class="text-xs text-muted-foreground"
                >
                    La cuenta no tiene correo registrado: entrega los accesos en
                    privado.
                </p>

                <DialogFooter class="gap-2 sm:justify-between">
                    <div class="flex flex-wrap gap-2">
                        <Button variant="outline" @click="copiar('ambos')">
                            <ClipboardCopy class="size-4" />
                            Copiar
                        </Button>
                        <Button
                            v-if="resultado.correo"
                            variant="outline"
                            :disabled="cargandoVistaPreviaCorreo"
                            @click="verVistaPreviaCorreo"
                        >
                            <Spinner v-if="cargandoVistaPreviaCorreo" />
                            <FileSearch v-else class="size-4" />
                            Ver correo
                        </Button>
                        <Button
                            v-if="resultado.correo"
                            variant="outline"
                            :disabled="enviando || correoEnviado"
                            @click="enviarCorreo"
                        >
                            <Spinner v-if="enviando" />
                            <Mail v-else class="size-4" />
                            {{
                                correoEnviado
                                    ? 'Correo enviado'
                                    : 'Enviar accesos por correo'
                            }}
                        </Button>
                    </div>
                    <Button @click="cerrar">Listo</Button>
                </DialogFooter>
            </template>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="vistaPreviaCorreoAbierta">
        <DialogContent
            class="flex h-[85vh] w-[calc(100vw-2rem)] max-w-none flex-col gap-3 sm:w-[min(92vw,760px)]"
        >
            <DialogHeader>
                <DialogTitle class="flex items-center gap-2">
                    <Mail class="size-4 text-primary" />
                    Vista previa del correo de accesos
                </DialogTitle>
                <DialogDescription v-if="vistaPreviaCorreo">
                    Para {{ vistaPreviaCorreo.destinatario }} · «{{
                        vistaPreviaCorreo.asunto
                    }}». La contraseña aparece enmascarada; el correo real la
                    lleva completa.
                </DialogDescription>
            </DialogHeader>
            <!-- sandbox vacío: el HTML del correo no ejecuta nada. -->
            <iframe
                v-if="vistaPreviaCorreo"
                :srcdoc="vistaPreviaCorreo.html"
                sandbox=""
                title="Vista previa del correo de accesos"
                class="min-h-0 w-full flex-1 rounded-lg border bg-card"
            />
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    @click="vistaPreviaCorreoAbierta = false"
                >
                    Cerrar
                </Button>
                <Button
                    :disabled="enviando || correoEnviado"
                    @click="
                        vistaPreviaCorreoAbierta = false;
                        enviarCorreo();
                    "
                >
                    <Mail class="size-4" />
                    {{
                        correoEnviado
                            ? 'Correo enviado'
                            : 'Enviar accesos por correo'
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
