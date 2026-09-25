<script setup lang="ts">
import { MapPin } from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import OrganigramaPersonaTarjeta from '@/components/Administracion/OrganigramaPersonaTarjeta.vue';
import { CLAVE_DETALLE_ORGANIGRAMA } from '@/lib/organigrama';
import type { DetalleOrganigrama } from '@/lib/organigrama';
import type { NodoOrganigramaPersona } from '@/types';

/**
 * Nodo recursivo del organigrama por personas: tarjeta + conectores hacia
 * sus subordinados. Cuando empieza la rama de una sucursal (un puesto de
 * sucursal cuyo superior es corporativo) se marca con el nombre de la
 * sucursal encima de la tarjeta.
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona;
    obtenerHijos: (clave: string) => NodoOrganigramaPersona[];
    padreDeSucursal?: boolean;
}>();

const colapsado = ref(false);
const detalle = inject(
    CLAVE_DETALLE_ORGANIGRAMA,
    ref<DetalleOrganigrama>('completo'),
);
const hijos = computed(() => props.obtenerHijos(props.nodo.clave));

const iniciaSucursal = computed(
    () =>
        props.nodo.de_sucursal &&
        props.nodo.sucursal !== null &&
        !props.padreDeSucursal,
);

function contarDebajo(clave: string): number {
    return props
        .obtenerHijos(clave)
        .reduce((total, hijo) => total + 1 + contarDebajo(hijo.clave), 0);
}
</script>

<template>
    <div class="flex flex-col items-center">
        <div
            v-if="iniciaSucursal"
            class="mb-2 inline-flex items-center gap-1.5 rounded-full bg-primary font-semibold text-primary-foreground shadow-sm"
            :class="
                detalle === 'minimo'
                    ? 'px-5 py-2 text-2xl'
                    : 'px-3 py-1 text-sm'
            "
        >
            <MapPin :class="detalle === 'minimo' ? 'size-6' : 'size-4'" />
            {{ nodo.sucursal?.nombre }}
        </div>
        <div
            v-else-if="nodo.region"
            class="mb-2 inline-flex items-center gap-1.5 rounded-full bg-sky-600 font-semibold text-white shadow-sm"
            :class="
                detalle === 'minimo'
                    ? 'px-5 py-2 text-2xl'
                    : 'px-3 py-1 text-sm'
            "
        >
            <MapPin :class="detalle === 'minimo' ? 'size-6' : 'size-4'" />
            {{ nodo.region.nombre }}
        </div>

        <OrganigramaPersonaTarjeta
            :nodo="nodo"
            :tiene-hijos="hijos.length > 0"
            :colapsado="colapsado"
            :total-debajo="colapsado ? contarDebajo(nodo.clave) : 0"
            @alternar-colapso="colapsado = !colapsado"
        />

        <template v-if="hijos.length && !colapsado">
            <div class="h-6 w-0.5 rounded-full bg-primary/25" />
            <div class="flex items-start justify-center">
                <div
                    v-for="(hijo, indice) in hijos"
                    :key="hijo.clave"
                    class="relative flex flex-col items-center px-2"
                >
                    <div
                        v-if="hijos.length > 1"
                        class="absolute top-0 h-0.5 rounded-full bg-primary/25"
                        :class="[
                            indice === 0
                                ? 'right-0 left-1/2'
                                : indice === hijos.length - 1
                                  ? 'right-1/2 left-0'
                                  : 'inset-x-0',
                        ]"
                    />
                    <div class="h-6 w-0.5 rounded-full bg-primary/25" />
                    <OrganigramaPersonaNodo
                        :nodo="hijo"
                        :obtener-hijos="obtenerHijos"
                        :padre-de-sucursal="nodo.de_sucursal"
                    />
                </div>
            </div>
        </template>
    </div>
</template>
