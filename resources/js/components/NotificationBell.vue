<script setup lang="ts">
import { Bell } from '@lucide/vue';
import { ref, watch } from 'vue';
import ActivarNotificacionesEscritorio from '@/components/ActivarNotificacionesEscritorio.vue';
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
import { formatearFechaHora } from '@/lib/fechas';
import { colorClaseNotificacion } from '@/lib/notificacionColor';

const {
    noLeidas,
    recientes,
    llegadas,
    marcarTodasComoLeidas,
    abrirNotificacion,
} = useNotificaciones();

// La campana «suena» (se mece) cada vez que llega algo nuevo.
const sonando = ref(false);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(llegadas, () => {
    sonando.value = false;
    clearTimeout(temporizador);
    requestAnimationFrame(() => {
        sonando.value = true;
        temporizador = setTimeout(() => (sonando.value = false), 900);
    });
});

// Siempre pasa por el servidor (aunque ya esté leída): ahí se resuelve la
// pantalla exacta del recurso y si ya fue atendido.
function abrir(notificacion: NotificacionItem) {
    void abrirNotificacion(notificacion.id);
}
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="relative"
                data-tour="notificaciones"
                aria-label="Notificaciones"
            >
                <Bell class="size-5" :class="{ 'campana-suena': sonando }" />
                <span
                    v-if="noLeidas > 0"
                    class="pointer-events-none absolute -top-1 -right-1 size-4 latido-aviso rounded-full bg-destructive/60"
                    aria-hidden="true"
                />
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
                    class="text-xs text-primary underline-offset-2 hover:underline"
                    @click="marcarTodasComoLeidas"
                >
                    Marcar todas como leídas
                </button>
            </div>
            <DropdownMenuSeparator />

            <!-- Invita a activar las notificaciones de escritorio (Web
                 Push): desaparece en cuanto quedan activas. -->
            <ActivarNotificacionesEscritorio compacto />

            <p
                v-if="recientes.length === 0"
                class="px-2 py-3 text-sm text-muted-foreground"
            >
                No tienes notificaciones.
            </p>

            <DropdownMenuItem
                v-for="notificacion in recientes"
                :key="notificacion.id"
                class="group/aviso flex items-start gap-2.5 whitespace-normal"
                :class="{ 'bg-accent/50': !notificacion.leida }"
                @click="abrir(notificacion)"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-base transition-transform duration-200 group-hover/aviso:scale-110"
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
                        formatearFechaHora(notificacion.creada_en)
                    }}</span>
                </div>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
