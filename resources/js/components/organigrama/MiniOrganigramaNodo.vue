<script setup lang="ts">
import type { MiniOrganigramaNodo } from '@/types';

defineProps<{
    nodo: MiniOrganigramaNodo;
    raiz?: boolean;
}>();
</script>

<template>
    <li class="flex flex-col gap-2">
        <div
            class="flex flex-wrap items-center gap-2 rounded-xl border p-2.5"
            :class="
                raiz
                    ? 'border-[var(--mrl-primary)]/50 bg-[var(--mrl-primary)]/10'
                    : 'border-[var(--mrl-borde)] bg-[var(--mrl-surface)]'
            "
        >
            <span class="text-sm font-semibold text-[var(--mrl-texto)]">
                {{ nodo.nombre }}
            </span>

            <span
                v-if="nodo.ocupantes.length === 0"
                class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-xs text-[var(--mrl-texto-suave)]"
            >
                Vacante
            </span>
            <span
                v-for="o in nodo.ocupantes.slice(0, 3)"
                v-else
                :key="o.id"
                class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-xs text-[var(--mrl-texto)]"
            >
                {{ o.nombre }}<template v-if="o.sucursal"> · {{ o.sucursal }}</template>
            </span>
            <span
                v-if="nodo.ocupantes.length > 3"
                class="rounded-full bg-[var(--mrl-fondo)] px-2 py-0.5 text-xs text-[var(--mrl-texto-suave)]"
            >
                +{{ nodo.ocupantes.length - 3 }} más
            </span>
        </div>

        <ul
            v-if="nodo.hijos.length"
            class="ml-5 flex flex-col gap-2 border-l border-[var(--mrl-borde)] pl-4"
        >
            <MiniOrganigramaNodo
                v-for="hijo in nodo.hijos"
                :key="hijo.id"
                :nodo="hijo"
            />
        </ul>
    </li>
</template>
