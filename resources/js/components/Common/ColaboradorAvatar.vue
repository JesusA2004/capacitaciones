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
    xs: 'size-6 text-[10px]',
    sm: 'size-8 text-xs',
    md: 'size-10 text-sm',
    lg: 'size-14 text-base',
    xl: 'size-20 text-xl',
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
                'shrink-0 shadow-sm ring-2 ring-background',
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
            class="object-cover"
        />
        <AvatarFallback :class="cn('font-semibold', tono)">
            {{ getInitials(nombre) }}
        </AvatarFallback>
    </Avatar>
</template>
