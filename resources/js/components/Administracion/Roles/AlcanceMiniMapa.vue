<script setup lang="ts">
import { computed } from 'vue';
import { ICONO_NIVEL } from '@/lib/rolesIconos';
import { ETIQUETA_NIVEL } from '@/lib/rolesPermisos';
import type { NivelAlcance } from '@/types/roles';

/**
 * «Mini mapa» del alcance de datos: qué zonas ve (su sucursal, Corporativo,
 * su región, el resto de la empresa). Puramente visual; el texto explícito
 * lo da la etiqueta.
 */
const props = defineProps<{
    nivel: NivelAlcance | 'propios';
}>();

const ZONAS = [
    { clave: 'sucursal', etiqueta: 'Su sucursal' },
    { clave: 'corporativo', etiqueta: 'Corporativo' },
    { clave: 'region', etiqueta: 'Su región' },
    { clave: 'resto', etiqueta: 'Resto de la empresa' },
] as const;

const CUBRE: Record<NivelAlcance | 'propios', string[]> = {
    propios: [],
    equipo: [],
    sucursal: ['sucursal'],
    sucursal_corporativo: ['sucursal', 'corporativo'],
    region: ['sucursal', 'region'],
    todos: ['sucursal', 'corporativo', 'region', 'resto'],
};

const zonas = computed(() =>
    ZONAS.map((z) => ({ ...z, cubierta: CUBRE[props.nivel].includes(z.clave) })),
);
const icono = computed(() => ICONO_NIVEL[props.nivel]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5"
        :title="ETIQUETA_NIVEL[nivel]"
        :aria-label="`Alcance: ${ETIQUETA_NIVEL[nivel]}`"
        role="img"
    >
        <component :is="icono" class="size-4 text-primary" />
        <span class="inline-grid grid-cols-4 gap-0.5" aria-hidden="true">
            <span
                v-for="z in zonas"
                :key="z.clave"
                class="h-3 w-3 rounded-[3px] border"
                :class="
                    z.cubierta
                        ? 'border-primary/40 bg-salvia'
                        : 'border-border bg-muted/40'
                "
                :title="`${z.etiqueta}: ${z.cubierta ? 'sí' : 'no'}`"
            />
        </span>
    </span>
</template>
