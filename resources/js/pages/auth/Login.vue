<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Download, Lock, Mail, Smartphone } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as appIndex } from '@/routes/app';
import { store } from '@/routes/login';
import { request } from '@/routes/password';

defineOptions({
    layout: {
        title: 'Inicia sesión en tu cuenta',
        description: 'Ingresa tu correo y contraseña para acceder al portal',
    },
});

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <Head title="Iniciar sesión" />

    <div
        v-if="status"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ status }}
    </div>

    <Form
        v-bind="store.form()"
        :reset-on-success="['password']"
        v-slot="{ errors, processing }"
        class="flex flex-col gap-4"
    >
        <div class="grid gap-4">
            <div class="grid gap-2">
                <Label for="email">Correo electrónico</Label>
                <div class="group relative">
                    <Mail
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground transition-colors group-focus-within:text-primary"
                    />
                    <Input
                        id="email"
                        type="email"
                        name="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="correo@ejemplo.com"
                        class="h-10 pl-9"
                    />
                </div>
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
                    <Label for="password">Contraseña</Label>
                    <TextLink
                        v-if="canResetPassword"
                        :href="request()"
                        class="text-sm"
                        :tabindex="5"
                    >
                        ¿Olvidaste tu contraseña?
                    </TextLink>
                </div>
                <div class="group relative">
                    <Lock
                        class="pointer-events-none absolute top-1/2 left-3 z-10 size-4 -translate-y-1/2 text-muted-foreground transition-colors group-focus-within:text-primary"
                    />
                    <PasswordInput
                        id="password"
                        name="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Contraseña"
                        class="h-10 pl-9"
                    />
                </div>
                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center justify-between">
                <Label for="remember" class="flex items-center space-x-3">
                    <Checkbox id="remember" name="remember" :tabindex="3" />
                    <span>Recordarme</span>
                </Label>
            </div>

            <Button
                type="submit"
                class="mt-1 w-full"
                :tabindex="4"
                :disabled="processing"
                data-test="login-button"
            >
                <Spinner v-if="processing" />
                Iniciar sesión
            </Button>
        </div>

        <div class="text-center text-xs text-muted-foreground">
            ¿Olvidaste cómo acceder? Contacta a tu administrador de RH.
        </div>
    </Form>

    <a
        :href="appIndex.url()"
        class="group mt-4 flex items-center gap-3 rounded-2xl border border-border/60 bg-muted/30 p-3.5 transition-all duration-300 hover:border-primary/40 hover:bg-muted/50 hover:shadow-md sm:p-4"
    >
        <span
            class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
        >
            <Smartphone class="size-5" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-sm font-semibold text-foreground">
                Descargar la app móvil
            </p>
            <p class="text-xs text-muted-foreground">
                Accede a MR. LANA PEOPLE desde tu celular
            </p>
        </div>
        <Download
            class="size-4 shrink-0 text-muted-foreground transition-transform duration-300 group-hover:translate-y-0.5 group-hover:text-primary"
        />
    </a>

    <p class="mt-3 text-center text-xs text-muted-foreground/70">
        © {{ new Date().getFullYear() }} MR. LANA PEOPLE
    </p>
</template>
