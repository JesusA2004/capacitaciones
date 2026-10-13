<script setup lang="ts">
import { ChevronDown, ChevronUp, Users } from '@lucide/vue';
import type { PerfilPuestoDetalle } from '@/types';

/** Encabezado del descriptivo: puesto, área y su lugar en el organigrama. */
defineProps<{
    puesto: PerfilPuestoDetalle['puesto'];
    etiqueta?: string;
}>();
</script>

<template>
    <header
        class="relative overflow-hidden rounded-3xl bg-[var(--mrl-petroleo)] p-5 text-white sm:p-7"
    >
        <div
            class="pointer-events-none absolute -top-16 -right-10 size-56 rounded-full bg-[var(--mrl-dorado)]/15 blur-2xl"
        />
        <div
            class="relative flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between"
        >
            <div class="min-w-0">
                <p
                    v-if="etiqueta"
                    class="text-[11px] font-semibold tracking-[0.18em] text-[var(--mrl-dorado)] uppercase"
                >
                    {{ etiqueta }}
                </p>
                <h1 class="mt-1 font-serif text-3xl leading-tight sm:text-4xl">
                    {{ puesto.nombre }}
                </h1>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-sm">
                    <span
                        v-if="puesto.departamento"
                        class="rounded-full bg-white/10 px-3 py-1"
                        >{{ puesto.departamento }}</span
                    >
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1"
                    >
                        <Users class="size-3.5" /> {{ puesto.ocupantes }}
                    </span>
                </div>
            </div>

            <div
                class="flex shrink-0 flex-col gap-3 sm:flex-row sm:items-end lg:flex-col lg:items-end"
            >
                <div
                    class="flex flex-col gap-1 rounded-2xl bg-white/[0.07] px-4 py-3 text-sm"
                >
                    <p class="flex items-center gap-2">
                        <ChevronUp class="size-4 text-[var(--mrl-dorado)]" />
                        <span class="text-white/60">Reporta a</span>
                        <span class="font-medium">{{
                            puesto.reporta_a ?? '—'
                        }}</span>
                    </p>
                    <p class="flex items-start gap-2">
                        <ChevronDown
                            class="mt-0.5 size-4 shrink-0 text-[var(--mrl-dorado)]"
                        />
                        <span class="text-white/60">Supervisa</span>
                        <span class="font-medium">{{
                            puesto.supervisa.length
                                ? puesto.supervisa.join(', ')
                                : '—'
                        }}</span>
                    </p>
                </div>
                <slot />
            </div>
        </div>
    </header>
</template>
