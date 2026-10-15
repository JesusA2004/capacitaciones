<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Perfil',
                href: edit(),
            },
        ],
    },
});

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head title="Mi perfil" />

    <h1 class="sr-only">Mi perfil</h1>

    <div class="flex flex-col space-y-6">
        <Heading
            variant="small"
            title="Perfil"
            description="Actualiza tu correo electrónico"
        />

        <Form
            v-bind="ProfileController.update.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Nombre</Label>
                <Input
                    id="name"
                    class="mt-1 block w-full"
                    :model-value="user.name"
                    readonly
                    disabled
                    autocomplete="name"
                />
                <p class="text-xs text-muted-foreground">
                    Para corregir tu nombre, solicita un cambio de información a
                    RH.
                </p>
            </div>

            <div class="grid gap-2">
                <Label for="email">Correo electrónico (opcional)</Label>
                <Input
                    id="email"
                    type="email"
                    class="mt-1 block w-full"
                    name="email"
                    :default-value="user.email ?? ''"
                    autocomplete="email"
                    placeholder="Correo electrónico"
                />
                <p class="text-xs text-muted-foreground">
                    Para iniciar sesión usas tu usuario:
                    <span class="font-medium">{{ user.username }}</span>
                </p>
                <InputError class="mt-2" :message="errors.email" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" data-test="update-profile-button"
                    >Guardar</Button
                >
            </div>
        </Form>
    </div>
</template>
