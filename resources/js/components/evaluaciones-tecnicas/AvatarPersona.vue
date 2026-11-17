<script setup lang="ts">
import { ref } from 'vue';
import { iniciales } from '@/lib/evaluacionesTecnicas';
import { cn } from '@/lib/utils';

/** Foto del colaborador o sus iniciales sobre crema (si la foto falla, iniciales). */
withDefaults(
    defineProps<{
        nombre: string;
        fotoUrl?: string | null;
        tamano?: 'sm' | 'md' | 'lg';
    }>(),
    { fotoUrl: null, tamano: 'md' },
);

const fallo = ref(false);
const tamanos = {
    sm: 'size-8 text-[11px]',
    md: 'size-10 text-xs',
    lg: 'size-16 text-lg',
};
</script>

<template>
    <span
        :class="
            cn(
                'relative flex shrink-0 items-center justify-center overflow-hidden rounded-full bg-crema font-semibold text-bronce ring-2 ring-card',
                tamanos[tamano],
            )
        "
        aria-hidden="true"
    >
        <img
            v-if="fotoUrl && !fallo"
            :src="fotoUrl"
            alt=""
            class="size-full object-cover"
            loading="lazy"
            @error="fallo = true"
        />
        <template v-else>{{ iniciales(nombre) }}</template>
    </span>
</template>
