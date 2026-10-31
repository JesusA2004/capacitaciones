<script setup lang="ts">
import { ChevronRight, Shuffle, UserRound } from '@lucide/vue';
import { computed } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import {
    contarRama,
    lugarDeNodo,
} from '@/lib/organigramaEditable';
import type { NodoOrganigramaPersona, RegionOrganigrama } from '@/types';

defineOptions({ name: 'FilaListaOrganigrama' });

/**
 * Celular: una fila por nodo con sangría por nivel; la flecha despliega a
 * quienes le reportan y tocar la fila abre el panel con sus acciones.
 */
const props = defineProps<{
    nodo: NodoOrganigramaPersona;
    hijos: Map<string, NodoOrganigramaPersona[]>;
    regiones: RegionOrganigrama[];
    nivel: number;
    seleccion: string | null;
    abiertos: Set<string>;
    /** Coincidencias de la búsqueda (null = sin búsqueda). */
    coincidencias?: Set<string> | null;
}>();

const emit = defineEmits<{
    seleccionar: [clave: string];
    alternar: [clave: string];
}>();

const propios = computed(() => props.hijos.get(props.nodo.clave) ?? []);
const abierto = computed(() => props.abiertos.has(props.nodo.clave));
const total = computed(() => contarRama(props.nodo.clave, props.hijos));
const esPlaza = computed(() => props.nodo.tipo !== 'persona');
</script>

<template>
    <li>
        <div
            class="flex items-stretch gap-1 rounded-2xl border bg-card"
            :class="[
                esPlaza
                    ? 'border-dashed border-input bg-muted/50'
                    : 'border-border',
                nodo.tipo === 'cobertura' ? 'border-esmeralda/60 bg-menta' : '',
                seleccion === nodo.clave ? 'ring-2 ring-oro' : '',
                coincidencias?.has(nodo.clave) ? 'ring-2 ring-primary' : '',
                coincidencias && !coincidencias.has(nodo.clave)
                    ? 'opacity-50'
                    : '',
            ]"
            :style="{ marginLeft: `${Math.min(nivel, 6) * 12}px` }"
        >
            <button
                v-if="propios.length"
                type="button"
                class="flex w-9 shrink-0 items-center justify-center rounded-l-2xl text-muted-foreground hover:bg-muted"
                :aria-expanded="abierto"
                :aria-label="
                    abierto
                        ? 'Ocultar a quienes le reportan'
                        : 'Mostrar a quienes le reportan'
                "
                @click="emit('alternar', nodo.clave)"
            >
                <ChevronRight
                    class="size-4 transition-transform"
                    :class="abierto ? 'rotate-90' : ''"
                />
            </button>
            <span v-else class="w-9 shrink-0" />

            <button
                type="button"
                class="flex min-w-0 flex-1 items-center gap-2.5 py-2.5 pr-3 text-left"
                @click="emit('seleccionar', nodo.clave)"
            >
                <ColaboradorAvatar
                    v-if="nodo.persona && nodo.tipo === 'persona'"
                    :nombre="nodo.persona.nombre"
                    :foto-url="nodo.persona.foto_url"
                    tamano="md"
                />
                <span
                    v-else
                    class="flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-dashed border-input text-muted-foreground"
                >
                    <UserRound class="size-5" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold">
                        {{ esPlaza ? 'Vacante' : nodo.persona?.nombre }}
                    </span>
                    <span class="block truncate text-xs text-muted-foreground">
                        {{ nodo.puesto.nombre
                        }}<template v-if="lugarDeNodo(nodo)">
                            · {{ lugarDeNodo(nodo) }}</template
                        >
                    </span>
                    <span
                        v-if="nodo.tipo === 'cobertura' && nodo.persona"
                        class="flex items-center gap-1 truncate text-xs font-medium text-primary"
                    >
                        <Shuffle class="size-3" /> Cubre temporalmente:
                        {{ nodo.persona.nombre }}
                    </span>
                </span>
                <span class="flex shrink-0 flex-col items-end gap-1">
                    <span
                        v-if="propios.length"
                        class="text-[10px] text-muted-foreground tabular-nums"
                        >{{ total }} a cargo</span
                    >
                </span>
            </button>
        </div>

        <ul
            v-if="abierto && propios.length"
            class="mt-1.5 flex flex-col gap-1.5"
        >
            <FilaListaOrganigrama
                v-for="h in propios"
                :key="h.clave"
                :nodo="h"
                :hijos="hijos"
                :regiones="regiones"
                :nivel="nivel + 1"
                :seleccion="seleccion"
                :abiertos="abiertos"
                :coincidencias="coincidencias"
                @seleccionar="(c) => emit('seleccionar', c)"
                @alternar="(c) => emit('alternar', c)"
            />
        </ul>
    </li>
</template>
