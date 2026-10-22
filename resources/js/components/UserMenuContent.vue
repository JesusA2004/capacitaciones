<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { LogOut, Settings } from '@lucide/vue';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import UserInfo from '@/components/UserInfo.vue';
import { olvidarSuscripcionAlSalir } from '@/composables/useWebPush';
import { logout } from '@/routes';
import { edit } from '@/routes/profile';
import type { User } from '@/types';

type Props = {
    user: User;
};

// Antes de cerrar la sesión, este navegador deja de recibir las
// notificaciones de escritorio de la cuenta (useWebPush).
const handleLogout = async () => {
    await Promise.race([
        olvidarSuscripcionAlSalir(),
        new Promise((resolver) => setTimeout(resolver, 1500)),
    ]);
    router.flushAll();
    router.post(logout().url);
};

defineProps<Props>();
</script>

<template>
    <DropdownMenuLabel class="p-0 font-normal">
        <div class="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
            <UserInfo :user="user" :show-email="true" />
        </div>
    </DropdownMenuLabel>
    <DropdownMenuSeparator />
    <DropdownMenuGroup>
        <DropdownMenuItem :as-child="true">
            <Link class="block w-full cursor-pointer" :href="edit()" prefetch>
                <Settings class="mr-2 h-4 w-4" />
                Configuración
            </Link>
        </DropdownMenuItem>
    </DropdownMenuGroup>
    <DropdownMenuSeparator />
    <DropdownMenuItem :as-child="true">
        <button
            type="button"
            class="block w-full cursor-pointer"
            data-test="logout-button"
            @click="handleLogout"
        >
            <LogOut class="mr-2 h-4 w-4" />
            Cerrar sesión
        </button>
    </DropdownMenuItem>
</template>
