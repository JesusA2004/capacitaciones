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
import { colorClaseNotificacion } from '@/lib/notificacionColor';

const { noLeidas, recientes, marcarComoLeida, marcarTodasComoLeidas } =
    useNotificaciones();

/**
 * reka-ui no registra el disparador del DropdownMenu como "branch" exento
 * de la detección de clics externos (a diferencia de los popovers anidados,
 * ver node_modules/reka-ui/dist/DismissableLayer/context.js). Por eso, al
 * volver a pulsar la campana con el menú abierto, el "pointerdown" cierra
 * el menú por "clic fuera" antes de que el "click" del mismo gesto llegue
 * al botón, y ese click vuelve a alternar el estado a abierto: el menú
 * nunca se percibe como cerrado.
 *
 * Se marca el cierre-por-fuera en cuanto ocurre y se descarta únicamente
 * el "click" del disparador que pertenece a ese mismo gesto (se libera en
 * el siguiente tick del bucle de eventos, no tras un tiempo fijo, para no
 * afectar un clic real posterior).
 */
let descartarClicDelDisparador = false;

function alCerrarPorFuera() {
    descartarClicDelDisparador = true;
    setTimeout(() => {
        descartarClicDelDisparador = false;
    }, 0);
}

function alHacerClicEnDisparador(event: MouseEvent) {
    if (descartarClicDelDisparador) {
        descartarClicDelDisparador = false;
        event.stopPropagation();
        event.preventDefault();
    }
}

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
        <span class="contents" @click.capture="alHacerClicEnDisparador">
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
        </span>
        <DropdownMenuContent
            align="end"
            class="w-80"
            @pointer-down-outside="alCerrarPorFuera"
            @focus-outside="alCerrarPorFuera"
        >
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
                class="flex items-start gap-2.5 whitespace-normal"
                :class="{ 'bg-accent/50': !notificacion.leida }"
                @click="abrir(notificacion)"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-base"
                    :class="colorClaseNotificacion(notificacion.color)"
                >
                    {{ notificacion.emoji }}
                </span>
                <div class="flex flex-col items-start gap-0.5">
                    <span class="text-sm font-medium">{{
                        notificacion.titulo
                    }}</span>
                    <span class="text-xs text-muted-foreground">{{
                        notificacion.mensaje
                    }}</span>
                    <span class="text-[10px] text-muted-foreground">{{
                        notificacion.creada_en
                    }}</span>
                </div>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
