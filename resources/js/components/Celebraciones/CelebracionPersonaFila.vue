<script setup lang="ts">
import { computed } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/composables/useInitials';
import { fechaCorta, fechaRelativa, subtituloPersona } from '@/lib/celebraciones';
import type { EventoCelebracion } from '@/types';

/**
 * Fila de directorio de una celebración: foto · nombre completo (hasta 2
 * líneas, nunca "Roberto Galicia V…") · puesto · sucursal · detalle, y a la
 * derecha la fecha («25 SEP» / «En 3 días»). Sin caja propia: la lista que
 * la contiene separa las filas con un borde sutil.
 */
const props = withDefaults(
    defineProps<{
        evento: EventoCelebracion;
        /** Fecha de hoy (Y-m-d) según el backend. */
        hoy: string;
        mostrarFecha?: boolean;
    }>(),
    { mostrarFecha: true },
);

const { getInitials } = useInitials();
const fecha = computed(() => fechaCorta(props.evento.fecha));
const subtitulo = computed(() => subtituloPersona(props.evento));
</script>

<template>
    <div class="flex min-w-0 items-center gap-3 py-2.5">
        <Avatar class="size-10 shrink-0">
            <AvatarImage v-if="evento.foto_url" :src="evento.foto_url" :alt="`Foto de ${evento.nombre}`" />
            <AvatarFallback>{{ getInitials(evento.nombre) }}</AvatarFallback>
        </Avatar>
        <div class="min-w-0 flex-1">
            <p class="line-clamp-3 text-sm leading-snug font-medium break-words @sm:line-clamp-2" :title="evento.nombre">{{ evento.nombre }}</p>
            <p v-if="subtitulo" class="text-xs leading-snug break-words text-muted-foreground">{{ subtitulo }}</p>
            <p v-if="evento.detalle" class="text-xs font-medium text-amber-700 dark:text-amber-300">{{ evento.detalle }}</p>
        </div>
        <div v-if="mostrarFecha" class="w-16 shrink-0 text-right">
            <p class="text-sm font-semibold tabular-nums" :class="evento.es_hoy && 'text-primary'">
                {{ fecha.dia }} <span class="text-xs">{{ fecha.mes }}</span>
            </p>
            <p class="text-[11px] leading-tight text-muted-foreground">{{ fechaRelativa(evento.fecha, hoy) }}</p>
        </div>
    </div>
</template>
