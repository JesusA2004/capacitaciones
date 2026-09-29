<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    ArrowLeftRight,
    Building2,
    ChevronDown,
    UserRoundSearch,
} from '@lucide/vue';
import { computed, inject, ref } from 'vue';
import { CLAVE_ACCIONES_ORGANIGRAMA } from '@/lib/organigrama';
import type { NodoOrganigramaPersona } from '@/types';

/**
 * Una fila de la lista jerárquica del organigrama en celular (el árbol no
 * cabe en 360–430 px): foto o iniciales, nombre (o VACANTE), puesto y
 * sucursal/región, cobertura temporal y sus subordinados desplegables. Mismas
 * acciones que la tarjeta del árbol (OrganigramaPersonaTarjeta.vue).
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona;
    obtenerHijos: (clave: string) => NodoOrganigramaPersona[];
    nivel: number;
}>();

const acciones = inject(CLAVE_ACCIONES_ORGANIGRAMA, null);
const hijos = computed(() => props.obtenerHijos(props.nodo.clave));
// Abiertos los dos primeros niveles (Dirección General y Dirección Comercial).
const abierto = ref(props.nivel < 2);

const esVacante = computed(() => props.nodo.tipo === 'vacante');
const esCobertura = computed(() => props.nodo.tipo === 'cobertura');
const ubicacion = computed(
    () => props.nodo.sucursal?.nombre ?? props.nodo.region?.nombre ?? null,
);
const iniciales = computed(() =>
    (props.nodo.persona?.nombre ?? '')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0])
        .join('')
        .toUpperCase(),
);

function abrirExpediente(): void {
    if (props.nodo.persona && !esVacante.value) {
        router.visit(props.nodo.persona.expediente_url);
    }
}
</script>

<template>
    <li>
        <div
            class="flex items-start gap-3 rounded-xl border p-3"
            :class="
                esVacante
                    ? 'border-dashed border-orange-300 bg-orange-50/60 dark:border-orange-500/40 dark:bg-orange-500/10'
                    : esCobertura
                      ? 'border-amber-300 bg-amber-50/70 dark:border-amber-500/50 dark:bg-amber-500/10'
                      : 'bg-card'
            "
        >
            <span
                v-if="esVacante"
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-orange-100 text-orange-700 dark:bg-orange-500/15 dark:text-orange-300"
            >
                <UserRoundSearch class="size-5" />
            </span>
            <img
                v-else-if="nodo.persona?.foto_url"
                :src="nodo.persona.foto_url"
                alt=""
                class="size-10 shrink-0 rounded-full object-cover"
            />
            <span
                v-else
                class="flex size-10 shrink-0 items-center justify-center rounded-full bg-muted text-sm font-semibold text-muted-foreground"
                >{{ iniciales }}</span
            >

            <button
                type="button"
                class="min-w-0 flex-1 text-left"
                :disabled="esVacante"
                @click="abrirExpediente"
            >
                <p
                    v-if="esVacante"
                    class="text-sm font-semibold text-orange-700 dark:text-orange-300"
                >
                    VACANTE
                </p>
                <p v-else class="text-sm leading-tight font-semibold">
                    {{ nodo.persona?.nombre }}
                </p>
                <p class="text-sm leading-tight text-muted-foreground">
                    {{ nodo.puesto.nombre }}
                </p>
                <p
                    v-if="ubicacion"
                    class="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground"
                >
                    <Building2 class="size-3.5 shrink-0" />
                    {{ ubicacion }}
                </p>
                <p
                    v-if="esCobertura && nodo.cobertura"
                    class="mt-1 flex items-start gap-1 text-xs text-amber-800 dark:text-amber-300"
                >
                    <ArrowLeftRight class="mt-0.5 size-3.5 shrink-0" />
                    <span
                        >Vacante · cubierto temporalmente. Titular de
                        {{ nodo.cobertura.titular_de ?? 'otro puesto' }}.</span
                    >
                </p>
                <p
                    v-for="ruta in nodo.rutas"
                    :key="`${ruta.tipo}-${ruta.nombre}`"
                    class="mt-0.5 text-xs text-muted-foreground"
                >
                    Ruta: {{ ruta.nombre }}
                </p>
            </button>

            <div class="flex shrink-0 flex-col items-end gap-1">
                <button
                    v-if="hijos.length"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-lg px-2 py-1 text-xs font-medium text-muted-foreground hover:bg-muted"
                    :aria-expanded="abierto"
                    @click="abierto = !abierto"
                >
                    {{ hijos.length }}
                    <ChevronDown
                        class="size-4 transition-transform"
                        :class="abierto && 'rotate-180'"
                    />
                </button>
                <button
                    v-if="esVacante && acciones?.puedeEditar"
                    type="button"
                    class="rounded-lg border border-orange-300 px-2 py-1 text-xs font-medium text-orange-700 dark:text-orange-300"
                    @click="acciones.asignarCobertura(nodo)"
                >
                    Cubrir
                </button>
                <button
                    v-if="esCobertura && acciones?.puedeEditar"
                    type="button"
                    class="rounded-lg border px-2 py-1 text-xs font-medium"
                    @click="acciones.terminarCobertura(nodo)"
                >
                    Terminar
                </button>
            </div>
        </div>

        <ul
            v-if="abierto && hijos.length"
            class="mt-2 ml-4 flex flex-col gap-2 border-l border-border pl-3"
        >
            <OrganigramaPersonaFila
                v-for="hijo in hijos"
                :key="hijo.clave"
                :nodo="hijo"
                :obtener-hijos="obtenerHijos"
                :nivel="nivel + 1"
            />
        </ul>
    </li>
</template>
