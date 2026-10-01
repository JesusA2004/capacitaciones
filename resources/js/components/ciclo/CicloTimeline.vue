<script setup lang="ts">
import { computed } from 'vue';
import type { ItemTimeline } from '@/types';

const props = defineProps<{ items: ItemTimeline[]; limite?: number }>();

const ordenados = computed(() => [...props.items].reverse().slice(0, props.limite ?? 200));

const colorEtapa: Record<string, string> = {
    reclutamiento: 'bg-[var(--mrl-cyan)]',
    aprobacion: 'bg-[var(--mrl-dorado)]',
    contratacion: 'bg-[var(--mrl-petroleo)]',
    onboarding: 'bg-[var(--mrl-verde)]',
    periodo_prueba: 'bg-[var(--mrl-navy)]',
    cierre: 'bg-[var(--mrl-rojo)]',
    reingreso: 'bg-[var(--mrl-verde-secundario)]',
};

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
}
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Línea de tiempo"
    >
        <h2 class="mb-4 text-sm font-semibold text-[var(--mrl-texto)]">Línea de tiempo</h2>
        <p v-if="!ordenados.length" class="text-sm text-[var(--mrl-texto-suave)]">Sin eventos registrados.</p>
        <ol v-else class="relative flex flex-col gap-4 border-l border-[var(--mrl-borde)] pl-5">
            <li v-for="(item, indice) in ordenados" :key="`${item.evento}-${indice}`" class="relative text-sm">
                <span
                    class="absolute top-1.5 -left-[25px] size-2.5 rounded-full ring-4 ring-[var(--mrl-superficie)]"
                    :class="colorEtapa[item.etapa] ?? 'bg-[var(--mrl-gris-verdoso)]'"
                />
                <p class="font-medium text-[var(--mrl-texto)]">{{ item.titulo }}</p>
                <p v-if="item.descripcion" class="text-[var(--mrl-texto)]/80">{{ item.descripcion }}</p>
                <p class="text-xs text-[var(--mrl-texto-suave)]">
                    {{ fecha(item.fecha) }}<template v-if="item.actor"> · {{ item.actor }}</template>
                </p>
            </li>
        </ol>
    </section>
</template>
