<script setup lang="ts">
import { ChevronRight } from '@lucide/vue';
import { computed, ref } from 'vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import type { NodoGeneral } from '@/types';

defineOptions({ name: 'OrganigramaGeneralNodo' });

const props = defineProps<{
    nodo: NodoGeneral;
    nivel: number;
}>();

const abierto = ref(props.nivel < 2);
const muchas = computed(() => props.nodo.personas.length > 4);
const verTodas = ref(false);
const visibles = computed(() =>
    muchas.value && !verTodas.value
        ? props.nodo.personas.slice(0, 4)
        : props.nodo.personas,
);
</script>

<template>
    <li class="relative">
        <div
            class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-2xl border px-3 py-2.5"
            :class="[
                nodo.grupo
                    ? 'border-dashed border-[var(--mrl-borde)] bg-[var(--mrl-fondo)]/60'
                    : nivel === 0
                      ? 'border-transparent bg-[var(--mrl-petroleo)] text-white'
                      : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]',
                nodo.apoyo ? 'border-dashed' : '',
            ]"
        >
            <button
                v-if="nodo.hijos.length"
                type="button"
                class="flex size-7 shrink-0 items-center justify-center rounded-lg hover:bg-black/5"
                :aria-expanded="abierto"
                :aria-label="
                    abierto
                        ? `Contraer ${nodo.titulo}`
                        : `Expandir ${nodo.titulo}`
                "
                @click="abierto = !abierto"
            >
                <ChevronRight
                    class="size-4 transition-transform"
                    :class="abierto ? 'rotate-90' : ''"
                />
            </button>
            <span v-else class="w-7 shrink-0" />

            <div class="min-w-[10rem] flex-1">
                <p
                    class="text-sm leading-tight font-semibold"
                    :class="nivel === 0 ? 'font-serif text-base' : ''"
                >
                    {{ nodo.titulo }}
                    <span
                        v-if="nodo.apoyo"
                        class="ml-1 text-[11px] font-normal text-muted-foreground"
                        >· apoyo</span
                    >
                </p>
                <p
                    v-if="!nodo.grupo && nodo.personas.length === 0"
                    class="text-xs italic"
                    :class="
                        nivel === 0 ? 'text-white/70' : 'text-muted-foreground'
                    "
                >
                    Vacante
                </p>
            </div>

            <ul
                v-if="nodo.personas.length"
                class="flex basis-full flex-wrap items-center gap-x-3 gap-y-1.5 pl-10 sm:basis-auto sm:pl-0"
            >
                <li
                    v-for="p in visibles"
                    :key="p.id ?? p.nombre"
                    class="flex items-center gap-1.5"
                >
                    <ColaboradorAvatar
                        v-if="p.id"
                        :nombre="p.nombre"
                        :foto-url="p.foto_url"
                        tamano="xs"
                    />
                    <span
                        class="text-xs"
                        :class="nivel === 0 ? 'text-white' : ''"
                        >{{ p.nombre }}</span
                    >
                </li>
                <li v-if="muchas">
                    <button
                        type="button"
                        class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-xs text-[var(--mrl-petroleo)] hover:underline"
                        @click="verTodas = !verTodas"
                    >
                        {{
                            verTodas
                                ? 'Ver menos'
                                : `+${nodo.personas.length - 4}`
                        }}
                    </button>
                </li>
            </ul>
        </div>

        <ul
            v-if="abierto && nodo.hijos.length"
            class="mt-2 ml-4 flex flex-col gap-2 border-l-2 pl-4 sm:ml-6 sm:pl-5"
            :class="
                nodo.hijos.every((h) => h.apoyo)
                    ? 'border-dashed border-[var(--mrl-dorado-oscuro)]/50'
                    : 'border-[var(--mrl-dorado)]/60'
            "
        >
            <OrganigramaGeneralNodo
                v-for="h in nodo.hijos"
                :key="h.clave"
                :nodo="h"
                :nivel="nivel + 1"
            />
        </ul>
    </li>
</template>
