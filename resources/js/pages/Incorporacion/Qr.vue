<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    CheckCircle2,
    Copy,
    Download,
    Globe,
    Smartphone,
    XCircle,
} from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { index as appIndex } from '@/routes/app';
import { registrar } from '@/routes/incorporacion/qr';
import { show as rutaAsistente } from '@/routes/mi-registro';

/**
 * Pantalla del QR de incorporación. Dos caminos con el MISMO registro:
 * la app (Android) o «Registrarme aquí en la web» (iPhone, Mac o quien no
 * pueda instalar la APK) — mismos datos que pide la app al crear la cuenta;
 * después sigue el asistente web (mi-registro).
 */
const props = defineProps<{
    valida: boolean;
    estado: string;
    message: string | null;
    token: string;
    appLink: string;
    codigoLegible: string | null;
    nombrePrellenado: string | null;
    prellenado: {
        nombre: string | null;
        email: string | null;
        telefono: string | null;
    } | null;
    registroEnCurso: boolean;
}>();

const enWeb = ref(false);
const copiado = ref(false);
const errorLocal = ref<string | null>(null);

async function copiarCodigo() {
    if (!props.codigoLegible) {
        return;
    }

    await navigator.clipboard.writeText(props.codigoLegible);
    copiado.value = true;
    setTimeout(() => {
        copiado.value = false;
    }, 2000);
}

/** Intenta abrir la app móvil vía deep link; si no está instalada, el
 * navegador simplemente se queda en esta misma pantalla. */
function continuarEnApp() {
    window.location.href = props.appLink;
}

/** Mismas validaciones rápidas que la app antes de enviar (el backend vuelve a validar). */
function antesDeEnviar(visita: { data: unknown }): boolean {
    const datos =
        visita.data instanceof FormData
            ? Object.fromEntries(visita.data.entries())
            : (visita.data as Record<string, unknown>);
    const password = String(datos.password ?? '');

    errorLocal.value = null;

    if (password.length < 8) {
        errorLocal.value = 'La contraseña debe tener al menos 8 caracteres.';
    } else if (password !== String(datos.password_confirmation ?? '')) {
        errorLocal.value = 'Las contraseñas no coinciden.';
    }

    return errorLocal.value === null;
}

const urlDescargarApp = appIndex.url({
    query: { from: 'qr', token: props.token },
});
</script>

<template>
    <Head title="Incorporación" />

    <div
        class="flex min-h-screen items-start justify-center bg-crema/40 px-4 py-8 sm:items-center"
    >
        <div
            class="w-full max-w-md rounded-3xl border border-border/60 bg-card p-6 shadow-sm sm:p-8"
        >
            <div class="mb-6 text-center">
                <h1 class="text-lg font-semibold tracking-tight">
                    MR. LANA PEOPLE
                </h1>
                <p class="text-sm text-muted-foreground">
                    Proceso de incorporación
                </p>
            </div>

            <div
                v-if="registroEnCurso"
                class="mb-5 rounded-2xl bg-salvia p-4 text-center text-sm"
            >
                <p class="mb-3 font-medium">Ya creaste tu cuenta.</p>
                <Button as-child class="w-full">
                    <Link :href="rutaAsistente()">Continuar mi registro</Link>
                </Button>
            </div>

            <template v-if="valida && !enWeb">
                <div class="text-center">
                    <CheckCircle2
                        class="mx-auto mb-3 size-12 text-[var(--success)]"
                    />
                    <p class="mb-1 text-base font-semibold">
                        ¡Bienvenido a MR. LANA!
                    </p>
                    <p
                        v-if="nombrePrellenado"
                        class="mb-4 text-sm text-muted-foreground"
                    >
                        {{ nombrePrellenado }}
                    </p>
                    <p class="mb-5 text-sm text-muted-foreground">
                        Elige dónde hacer tu registro. Es el mismo en los dos.
                    </p>
                </div>

                <div class="grid gap-3">
                    <button
                        type="button"
                        class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 text-left"
                        data-test="registro-web"
                        @click="enWeb = true"
                    >
                        <span
                            class="grid size-11 shrink-0 place-items-center rounded-xl bg-salvia text-[var(--mrl-petroleo)]"
                        >
                            <Globe class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold"
                                >Registrarme aquí en la web</span
                            >
                            <span class="block text-xs text-muted-foreground"
                                >iPhone, Mac o cualquier navegador</span
                            >
                        </span>
                    </button>

                    <button
                        type="button"
                        class="flex tarjeta-interactiva items-center gap-3 rounded-2xl border border-border/70 bg-card p-4 text-left"
                        @click="continuarEnApp"
                    >
                        <span
                            class="grid size-11 shrink-0 place-items-center rounded-xl bg-crema text-[var(--mrl-petroleo)]"
                        >
                            <Smartphone class="size-5" />
                        </span>
                        <span class="min-w-0">
                            <span class="block font-semibold"
                                >Continuar en la app</span
                            >
                            <span class="block text-xs text-muted-foreground"
                                >Si ya la tienes instalada (Android)</span
                            >
                        </span>
                    </button>

                    <Button as-child variant="outline" class="w-full">
                        <a :href="urlDescargarApp">
                            <Download class="size-4" /> Descargar app
                        </a>
                    </Button>
                </div>

                <div
                    v-if="codigoLegible"
                    class="mt-5 flex items-center justify-center gap-2 text-sm text-muted-foreground"
                >
                    <span
                        >Código: <strong>{{ codigoLegible }}</strong></span
                    >
                    <button
                        type="button"
                        class="inline-flex items-center gap-1 text-xs underline underline-offset-2 hover:text-foreground"
                        @click="copiarCodigo"
                    >
                        <Copy class="size-3.5" />
                        {{ copiado ? 'Copiado' : 'Copiar' }}
                    </button>
                </div>
            </template>

            <!-- Crear la cuenta en la web: mismos datos que la app. -->
            <template v-else-if="valida && enWeb">
                <button
                    type="button"
                    class="mb-4 inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    @click="enWeb = false"
                >
                    <ArrowLeft class="size-4" /> Volver
                </button>
                <h2 class="text-base font-semibold">Crea tu cuenta</h2>
                <p class="mb-5 text-sm text-muted-foreground">
                    Ten a la mano tu INE, CURP, RFC, NSS y comprobante de
                    domicilio. Tu avance se guarda solo.
                </p>

                <Form
                    v-bind="registrar.form(token)"
                    :reset-on-error="['password', 'password_confirmation']"
                    :on-before="antesDeEnviar"
                    v-slot="{ errors, processing }"
                    class="grid gap-4"
                >
                    <div class="grid gap-2">
                        <Label for="name">Nombre(s)</Label>
                        <Input
                            id="name"
                            name="name"
                            required
                            autocomplete="given-name"
                            placeholder="Como aparece en tu INE"
                            :default-value="prellenado?.nombre ?? ''"
                        />
                        <InputError :message="errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="apellidos">Apellidos</Label>
                        <Input
                            id="apellidos"
                            name="apellidos"
                            autocomplete="family-name"
                            placeholder="Paterno y materno"
                        />
                        <InputError :message="errors.apellidos" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="telefono">Teléfono celular</Label>
                        <Input
                            id="telefono"
                            name="telefono"
                            type="tel"
                            inputmode="numeric"
                            autocomplete="tel-national"
                            maxlength="10"
                            placeholder="10 dígitos"
                            :default-value="prellenado?.telefono ?? ''"
                        />
                        <InputError :message="errors.telefono" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="email"
                            >Correo electrónico
                            <span class="font-normal text-muted-foreground"
                                >(opcional)</span
                            ></Label
                        >
                        <Input
                            id="email"
                            name="email"
                            type="email"
                            autocomplete="email"
                            placeholder="tucorreo@ejemplo.com"
                            :default-value="prellenado?.email ?? ''"
                        />
                        <InputError :message="errors.email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password">Contraseña</Label>
                        <PasswordInput
                            id="password"
                            name="password"
                            required
                            autocomplete="new-password"
                            placeholder="Mínimo 8 caracteres"
                        />
                        <InputError :message="errors.password" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="password_confirmation"
                            >Confirmar contraseña</Label
                        >
                        <PasswordInput
                            id="password_confirmation"
                            name="password_confirmation"
                            required
                            autocomplete="new-password"
                            placeholder="Repite tu contraseña"
                        />
                        <InputError
                            :message="
                                errors.password_confirmation ??
                                errorLocal ??
                                undefined
                            "
                        />
                    </div>

                    <p
                        v-if="errors.registro"
                        class="rounded-xl bg-danger-soft px-3 py-2 text-sm"
                        role="alert"
                    >
                        {{ errors.registro }}
                    </p>

                    <Button
                        type="submit"
                        class="w-full"
                        :disabled="processing"
                        data-test="crear-cuenta"
                    >
                        <Spinner v-if="processing" />
                        Crear mi cuenta y seguir
                    </Button>
                </Form>
            </template>

            <template v-else-if="!registroEnCurso">
                <div class="text-center">
                    <XCircle class="mx-auto mb-3 size-12 text-destructive" />
                    <p class="mb-4 text-sm font-medium">
                        Este código de incorporación no es válido, venció o fue
                        revocado. Solicita uno nuevo a Recursos Humanos.
                    </p>

                    <Button as-child variant="outline" class="w-full">
                        <Link :href="login()">Ir al login</Link>
                    </Button>
                </div>
            </template>
        </div>
    </div>
</template>
