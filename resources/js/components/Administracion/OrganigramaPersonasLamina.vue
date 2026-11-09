<script setup lang="ts">
import { computed } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import type { EstadoCajaLamina, PersonaLamina } from '@/types';

/**
 * Titulares de una caja de la lámina: foto (o iniciales) + nombre completo.
 * Con muchas personas (p. ej. gestores) se muestran como chips compactos.
 * Quien sigue en incorporación lleva el chip de su fase («Inducción»…).
 * Sin nadie: «Por ingresar: X» (candidato autorizado por RH), «Puesto no
 * registrado» (ningún puesto del catálogo coincide) o «Vacante».
 */
const props = withDefaults(
    defineProps<{
        personas: PersonaLamina[];
        conSede?: boolean;
        centrado?: boolean;
        vacante?: string;
        estado?: EstadoCajaLamina | null;
        /** Lista de candidatos por ingresar (si no viene en `estado`). */
        porIngresar?: string[];
    }>(),
    {
        conSede: false,
        centrado: false,
        vacante: 'Vacante',
        estado: null,
        porIngresar: () => [],
    },
);

const enCamino = computed(() =>
    props.porIngresar.length
        ? props.porIngresar
        : (props.estado?.por_ingresar ?? []),
);
const noRegistrado = computed(() => props.estado?.registrado === false);
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
            class="inline-flex max-w-full flex-wrap items-center gap-1.5"
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
            <span
                v-if="p.incorporacion"
                class="rounded-full border border-info/30 bg-info-soft px-1.5 py-px text-[10px] font-semibold text-info not-italic"
                :title="`En incorporación: ${p.incorporacion}`"
                >{{ p.incorporacion }}</span
            >
        </span>
    </span>
    <span
        v-else-if="enCamino.length"
        class="rounded-md border border-dashed border-info/40 bg-info-soft px-1.5 py-0.5 text-[11px] font-medium text-info not-italic"
        >Por ingresar: {{ enCamino.join(', ') }}</span
    >
    <span
        v-else-if="noRegistrado"
        class="rounded-md border border-dashed border-warning/40 bg-warning-soft px-1.5 py-0.5 text-[11px] font-medium text-warning not-italic"
        title="Ningún puesto del catálogo coincide con esta caja: regístralo en Administración › Puestos para que aparezca."
        >Puesto no registrado</span
    >
    <span v-else class="italic opacity-70">{{ vacante }}</span>
</template>
