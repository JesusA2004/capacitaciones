<script setup lang="ts">
import { computed } from 'vue';
import OrganigramaPersonaFila from '@/components/Administracion/OrganigramaPersonaFila.vue';
import { agruparPersonasPorPadre } from '@/lib/organigrama';
import type { NodoOrganigramaPersona } from '@/types';

/**
 * Organigrama por personas en celular: lista jerárquica desplegable (el
 * árbol con zoom no se lee en 360–430 px). Mismo orden y mismos datos que
 * OrganigramaPersonasArbol.vue.
 */
const props = defineProps<{ nodos: NodoOrganigramaPersona[] }>();

const hijosPorPadre = computed(() => agruparPersonasPorPadre(props.nodos));
const raices = computed(() => hijosPorPadre.value.get('') ?? []);

function obtenerHijos(clave: string): NodoOrganigramaPersona[] {
    return hijosPorPadre.value.get(clave) ?? [];
}
</script>

<template>
    <ul class="flex flex-col gap-2" aria-label="Organigrama">
        <OrganigramaPersonaFila
            v-for="raiz in raices"
            :key="raiz.clave"
            :nodo="raiz"
            :obtener-hijos="obtenerHijos"
            :nivel="0"
        />
    </ul>
</template>
