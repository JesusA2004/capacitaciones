<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useNotificaciones } from '@/composables/useNotificaciones';
import type { NotificacionItem } from '@/composables/useNotificaciones';

const { noLeidas, recientes, marcarComoLeida, marcarTodasComoLeidas } =
    useNotificaciones();

async function abrir(notificacion: NotificacionItem) {
    if (!notificacion.leida) {
        await marcarComoLeida(notificacion.id);
    }

    if (notificacion.url) {
        router.visit(notificacion.url);
    }
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="icon" class="relative">
                <Bell class="size-5" />
                <Badge
                    v-if="noLeidas > 0"
                    variant="destructive"
                    class="absolute -top-1 -right-1 flex size-4 items-center justify-center rounded-full p-0 text-[10px]"
                >
                    {{ noLeidas > 9 ? '9+' : noLeidas }}
                </Badge>
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-80">
            <div class="flex items-center justify-between px-2 py-1.5">
                <DropdownMenuLabel class="p-0"
                    >Notificaciones</DropdownMenuLabel
                >
                <button
                    v-if="noLeidas > 0"
                    type="button"
                    class="text-xs text-primary underline"
                    @click="marcarTodasComoLeidas"
                >
                    Marcar todas como leídas
                </button>
            </div>
            <DropdownMenuSeparator />

            <p
                v-if="recientes.length === 0"
                class="px-2 py-3 text-sm text-muted-foreground"
            >
                No tienes notificaciones.
            </p>

            <DropdownMenuItem
                v-for="notificacion in recientes"
                :key="notificacion.id"
                class="flex flex-col items-start gap-0.5 whitespace-normal"
                :class="{ 'bg-accent/50': !notificacion.leida }"
                @click="abrir(notificacion)"
            >
                <span class="text-sm font-medium">{{
                    notificacion.titulo
                }}</span>
                <span class="text-xs text-muted-foreground">{{
                    notificacion.mensaje
                }}</span>
                <span class="text-[10px] text-muted-foreground">{{
                    notificacion.creada_en
                }}</span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
