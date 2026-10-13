<script setup lang="ts">
import { CircleCheck, Target } from '@lucide/vue';
import type { EstiloLista } from '@/lib/perfilPuesto';

/** Una sección del descriptivo/perfil en modo LECTURA. */
defineProps<{
    titulo: string;
    estilo: EstiloLista;
    items: string[];
}>();
</script>

<template>
    <section v-if="items.length" class="flex flex-col gap-3">
        <h3
            class="flex items-center gap-2 text-xs font-semibold tracking-[0.14em] text-[var(--mrl-dorado-oscuro)] uppercase"
        >
            {{ titulo }}
            <span
                class="rounded-full bg-[var(--mrl-fondo)] px-1.5 py-px text-[10px] tracking-normal text-muted-foreground"
                >{{ items.length }}</span
            >
        </h3>

        <ol v-if="estilo === 'numerada'" class="grid gap-2 sm:grid-cols-2">
            <li
                v-for="(item, i) in items"
                :key="item"
                class="flex items-start gap-3 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 text-sm"
            >
                <span
                    class="flex size-6 shrink-0 items-center justify-center rounded-full bg-[var(--mrl-petroleo)] text-[11px] font-semibold text-white"
                    >{{ i + 1 }}</span
                >
                <span class="pt-0.5 leading-snug">{{ item }}</span>
            </li>
        </ol>

        <ul v-else-if="estilo === 'checklist'" class="flex flex-col gap-2">
            <li
                v-for="item in items"
                :key="item"
                class="flex items-start gap-2.5 text-sm leading-snug"
            >
                <CircleCheck
                    class="mt-0.5 size-4 shrink-0 text-[var(--mrl-verde)]"
                />
                {{ item }}
            </li>
        </ul>

        <div
            v-else-if="estilo === 'indicadores'"
            class="grid gap-2 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="item in items"
                :key="item"
                class="flex items-center gap-3 rounded-xl bg-[var(--mrl-crema)] p-3 text-sm"
            >
                <span
                    class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[var(--mrl-superficie)] text-[var(--mrl-dorado-oscuro)]"
                >
                    <Target class="size-4" />
                </span>
                <span class="leading-snug font-medium">{{ item }}</span>
            </div>
        </div>

        <ul v-else class="flex flex-wrap gap-2">
            <li
                v-for="item in items"
                :key="item"
                class="rounded-full border border-[var(--mrl-petroleo)]/20 bg-[var(--mrl-petroleo)]/[0.06] px-3 py-1 text-sm text-[var(--mrl-petroleo)]"
            >
                {{ item }}
            </li>
        </ul>
    </section>
</template>
