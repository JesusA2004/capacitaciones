<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { update } from '@/routes/contrasena-temporal';

defineOptions({
    layout: {
        title: 'Crea tu contraseña',
        description:
            'Entraste con una contraseña temporal. Antes de continuar, define una contraseña personal.',
    },
});

defineProps<{
    username: string;
    passwordRules: string;
}>();
</script>

<template>
    <Head title="Cambiar contraseña temporal" />

    <Form
        v-bind="update.form()"
        :reset-on-success="['password', 'password_confirmation']"
        v-slot="{ errors, processing }"
    >
        <div class="grid gap-5">
            <div
                class="flex items-start gap-3 rounded-xl border border-border/60 bg-muted/30 p-3 text-sm"
            >
                <ShieldCheck class="mt-0.5 size-5 shrink-0 text-primary" />
                <p>
                    Tu usuario es
                    <span class="font-semibold">{{ username }}</span
                    >. La contraseña temporal deja de funcionar en cuanto
                    guardes la nueva.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="password">Nueva contraseña</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    autocomplete="new-password"
                    autofocus
                    required
                    placeholder="Nueva contraseña"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password" />
            </div>

            <div class="grid gap-2">
                <Label for="password_confirmation">
                    Confirmar contraseña
                </Label>
                <PasswordInput
                    id="password_confirmation"
                    name="password_confirmation"
                    autocomplete="new-password"
                    required
                    placeholder="Confirmar contraseña"
                    :passwordrules="passwordRules"
                />
                <InputError :message="errors.password_confirmation" />
            </div>

            <Button
                type="submit"
                class="w-full"
                :disabled="processing"
                data-test="cambiar-contrasena-button"
            >
                <Spinner v-if="processing" />
                Guardar y continuar
            </Button>

            <Link
                :href="logout()"
                as="button"
                class="text-center text-sm text-muted-foreground underline-offset-4 hover:underline"
            >
                Cerrar sesión
            </Link>
        </div>
    </Form>
</template>
