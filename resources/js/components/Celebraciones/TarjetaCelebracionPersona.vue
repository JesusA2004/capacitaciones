<script setup lang="ts">
import { PartyPopper } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * Tarjeta de persona para Cumpleaños y Aniversarios (banner "Hoy").
 *
 * Se adapta al ancho REAL de la tarjeta (container queries), no al de la
 * pantalla: dentro de una rejilla de 3 columnas en desktop la tarjeta mide
 * ~350 px aunque la ventana mida 1366, y antes los 5 botones en fila se
 * salían y quedaban cortados. Ahora:
 *  - angosta (< 28rem): datos arriba, acciones en rejilla de 2 columnas;
 *  - media: acciones con wrap bajo los datos;
 *  - ancha (≥ 40rem): datos y acciones en una fila si caben.
 * Nunca se recorta un botón (sin overflow-hidden sobre las acciones).
 */
withDefaults(
    defineProps<{
        nombre: string;
        detalle: string;
        fotoUrl: string | null;
        destacado?: boolean;
    }>(),
    { destacado: false },
);

const { getInitials } = useInitials();
</script>

<template>
    <div class="@container min-w-0">
        <div
            class="relative flex min-w-0 flex-col gap-3 rounded-xl border p-3 @[40rem]:flex-row @[40rem]:items-center @[40rem]:justify-between"
            :class="
                destacado
                    ? 'border-transparent bg-gradient-to-br from-amber-400/15 via-pink-400/10 to-violet-400/15 shadow-sm ring-1 ring-amber-400/30'
                    : 'bg-background'
            "
        >
            <!-- Adorno recortado en su propia capa: nunca recorta botones. -->
            <div v-if="destacado" class="pointer-events-none absolute inset-0 overflow-hidden rounded-xl" aria-hidden="true">
                <PartyPopper class="absolute -top-2 -right-2 size-16 rotate-12 text-amber-400/20" />
            </div>

            <div class="relative flex min-w-0 items-center gap-3">
                <Avatar class="size-11 shrink-0 ring-2" :class="destacado ? 'ring-amber-400/50' : 'ring-background'">
                    <AvatarImage v-if="fotoUrl" :src="fotoUrl" :alt="nombre" />
                    <AvatarFallback>{{ getInitials(nombre) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="flex items-center gap-1.5 font-medium break-words">
                        {{ nombre }}
                        <slot name="icono" />
                    </p>
                    <p class="text-xs text-muted-foreground break-words">{{ detalle }}</p>
                    <slot name="extra" />
                </div>
            </div>

            <div
                class="relative grid grid-cols-2 gap-1.5 @[28rem]:flex @[28rem]:flex-wrap @[40rem]:shrink-0 @[40rem]:justify-end [&>*]:min-w-0 [&>*]:justify-center"
            >
                <slot name="acciones" />
            </div>
        </div>
    </div>
</template>
