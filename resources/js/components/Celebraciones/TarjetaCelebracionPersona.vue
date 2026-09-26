<script setup lang="ts">
import { PartyPopper } from '@lucide/vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';

/**
 * Tarjeta de persona del bloque "Hoy" de Cumpleaños y Aniversarios. Es
 * genérica: no sabe de edades ni de años de servicio, solo presenta
 * nombre / subtítulo (puesto · sucursal) / detalle y las acciones que le
 * pasen.
 *
 * Se adapta al ancho REAL de la tarjeta (container queries), no al de la
 * pantalla — dentro de una rejilla de 3 columnas en desktop la tarjeta mide
 * ~400 px aunque la ventana mida 1440:
 *  - angosta (< 30rem): datos arriba, acciones en rejilla de 2 columnas
 *    (un botón impar ocupa la fila completa, no queda huérfano);
 *  - media: acciones con wrap bajo los datos;
 *  - ancha (≥ 56rem, p. ej. una sola persona hoy): datos y acciones en fila.
 * Nunca se recorta un botón: el adorno con overflow-hidden vive en su propia
 * capa, separada de las acciones.
 */
withDefaults(
    defineProps<{
        nombre: string;
        subtitulo?: string | null;
        detalle?: string | null;
        fotoUrl: string | null;
        destacado?: boolean;
    }>(),
    { subtitulo: null, detalle: null, destacado: false },
);

const { getInitials } = useInitials();
</script>

<template>
    <article class="@container min-w-0">
        <div
            class="relative flex h-full min-w-0 flex-col gap-3 rounded-xl border p-3 sm:p-4 @[56rem]:flex-row @[56rem]:items-center @[56rem]:justify-between"
            :class="destacado ? 'border-amber-400/40 bg-gradient-to-br from-amber-400/10 via-pink-400/5 to-transparent' : 'bg-card'"
        >
            <div v-if="destacado" class="pointer-events-none absolute inset-0 overflow-hidden rounded-xl" aria-hidden="true">
                <PartyPopper class="absolute -top-2 -right-2 size-16 rotate-12 text-amber-400/15" />
            </div>

            <div class="relative flex min-w-0 items-center gap-3">
                <Avatar class="size-14 shrink-0 ring-2" :class="destacado ? 'ring-amber-400/50' : 'ring-border'">
                    <AvatarImage v-if="fotoUrl" :src="fotoUrl" :alt="`Foto de ${nombre}`" />
                    <AvatarFallback class="text-base">{{ getInitials(nombre) }}</AvatarFallback>
                </Avatar>
                <div class="min-w-0">
                    <p class="flex items-start gap-1.5 leading-snug font-semibold break-words">
                        <span class="min-w-0">{{ nombre }}</span>
                        <slot name="icono" />
                    </p>
                    <p v-if="detalle" class="text-sm font-medium text-amber-700 dark:text-amber-300">{{ detalle }}</p>
                    <p v-if="subtitulo" class="text-xs break-words text-muted-foreground">{{ subtitulo }}</p>
                    <slot name="extra" />
                </div>
            </div>

            <div
                v-if="$slots.acciones"
                class="relative grid grid-cols-2 gap-1.5 @[30rem]:flex @[30rem]:flex-wrap @[56rem]:max-w-[36rem] @[56rem]:shrink-0 @[56rem]:justify-end [&>*]:min-w-0 [&>*]:justify-center [&>*:last-child:nth-child(odd)]:col-span-2"
            >
                <slot name="acciones" />
            </div>
        </div>
    </article>
</template>
