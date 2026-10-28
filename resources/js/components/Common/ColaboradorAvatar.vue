<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { getInitials } from '@/composables/useInitials';
import { cn } from '@/lib/utils';

/**
 * Miniatura de la foto del colaborador, igual en todos los módulos
 * (expedientes, solicitudes, organigrama, cumpleaños…). Sin foto, muestra
 * sus iniciales sobre un tono estable derivado del nombre — así la misma
 * persona siempre tiene el mismo color y la lista no se ve monótona.
 */
const props = withDefaults(
    defineProps<{
        nombre: string;
        fotoUrl?: string | null;
        tamano?: 'xs' | 'sm' | 'md' | 'lg' | 'xl';
        class?: HTMLAttributes['class'];
    }>(),
    { fotoUrl: null, tamano: 'md', class: undefined },
);

const TAMANOS: Record<NonNullable<typeof props.tamano>, string> = {
    // Un paso más grandes que antes (2026-10-28): la foto se distingue
    // mejor en listas y tarjetas sin romper renglones compactos.
    xs: 'size-7 text-[10px]',
    sm: 'size-9 text-xs',
    md: 'size-11 text-sm',
    lg: 'size-16 text-lg',
    xl: 'size-24 text-2xl',
};

const TONOS = [
    'bg-info-soft text-info',
    'bg-success-soft text-success',
    'bg-crema text-bronce',
    'bg-warning-soft text-warning',
    'bg-success-soft text-success',
    'bg-info-soft text-info',
];

const tono = computed(() => {
    let suma = 0;

    for (const letra of props.nombre) {
        suma = (suma + letra.charCodeAt(0)) % 997;
    }

    return TONOS[suma % TONOS.length];
});
</script>

<template>
    <Avatar
        :class="
            cn(
                // Aro crema + leve acercamiento cuando la fila/tarjeta
                // (clase group) está bajo el cursor.
                'shrink-0 shadow-sm ring-2 ring-background transition-transform duration-200 ease-out group-hover:scale-105 motion-reduce:transition-none',
                TAMANOS[tamano],
                props.class,
            )
        "
    >
        <AvatarImage
            v-if="fotoUrl"
            :src="fotoUrl"
            :alt="nombre"
            loading="lazy"
            class="animate-in object-cover duration-300 fade-in"
        />
        <AvatarFallback :class="cn('font-semibold', tono)">
            {{ getInitials(nombre) }}
        </AvatarFallback>
    </Avatar>
</template>
