<script setup lang="ts">
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import type { PersonaLamina } from '@/types';

/**
 * Titulares de una caja de la lámina: foto (o iniciales) + nombre completo.
 * Con muchas personas (p. ej. gestores) se muestran como chips compactos.
 */
withDefaults(
    defineProps<{
        personas: PersonaLamina[];
        conSede?: boolean;
        centrado?: boolean;
        vacante?: string;
    }>(),
    { conSede: false, centrado: false, vacante: 'Vacante' },
);
</script>

<template>
    <span
        v-if="personas.length"
        class="flex flex-col gap-1"
        :class="centrado ? 'items-center' : 'items-start'"
    >
        <span
            v-for="p in personas"
            :key="p.id ?? p.nombre"
            class="inline-flex max-w-full items-center gap-1.5"
        >
            <ColaboradorAvatar
                v-if="p.id"
                :nombre="p.nombre"
                :foto-url="p.foto_url"
                tamano="sm"
            />
            <span class="min-w-0 text-left leading-tight"
                >{{ p.nombre
                }}<template v-if="conSede && p.sucursal">
                    · {{ p.sucursal }}</template
                ></span
            >
        </span>
    </span>
    <span v-else class="italic opacity-70">{{ vacante }}</span>
</template>
