<script setup lang="ts">
import { AlertTriangle, ArrowRight, UserRound } from '@lucide/vue';
import type { EstadoCiclo } from '@/types';

defineProps<{ ciclo: EstadoCiclo }>();

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleDateString('es-MX', { day: '2-digit', month: 'short', year: 'numeric' }) : '—';
}
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Estado actual"
    >
        <div class="flex flex-wrap items-center gap-2">
            <span
                class="rounded-full bg-[var(--mrl-petroleo)] px-3 py-1 text-xs font-semibold text-white"
            >
                <template v-if="ciclo.etapa.numero">Etapa {{ ciclo.etapa.numero }} · </template>{{ ciclo.etapa.etiqueta }}
            </span>
            <span class="text-sm font-semibold text-[var(--mrl-texto)]">{{
                ciclo.estado.etiqueta
            }}</span>
            <span class="ml-auto text-xs text-[var(--mrl-texto-suave)]"
                >Desde {{ fecha(ciclo.fecha_desde_estado) }}</span
            >
        </div>

        <div>
            <div class="mb-1 flex justify-between text-xs text-[var(--mrl-texto-suave)]">
                <span>Progreso</span><span>{{ ciclo.progreso }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-[var(--mrl-fondo)]">
                <div
                    class="h-full rounded-full bg-[var(--mrl-petroleo)] transition-all"
                    :style="{ width: `${ciclo.progreso}%` }"
                />
            </div>
        </div>

        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div class="flex items-start gap-2">
                <UserRound class="mt-0.5 size-4 text-[var(--mrl-petroleo)]" />
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">Responsable actual</dt>
                    <dd class="font-medium text-[var(--mrl-texto)]">
                        {{ ciclo.responsable_actual.rol }}
                        <span v-if="ciclo.responsable_actual.nombre" class="font-normal">
                            · {{ ciclo.responsable_actual.nombre }}</span
                        >
                    </dd>
                </div>
            </div>
            <div class="flex items-start gap-2">
                <ArrowRight class="mt-0.5 size-4 text-[var(--mrl-dorado)]" />
                <div>
                    <dt class="text-xs text-[var(--mrl-texto-suave)]">Siguiente acción</dt>
                    <dd class="font-medium text-[var(--mrl-texto)]">
                        {{ ciclo.siguiente_accion?.etiqueta ?? 'Sin acciones pendientes' }}
                    </dd>
                </div>
            </div>
        </dl>

        <ul
            v-if="ciclo.bloqueos.length"
            class="flex flex-col gap-1.5 rounded-xl border border-[var(--mrl-rojo)]/25 bg-[var(--mrl-rojo)]/5 p-3"
        >
            <li
                v-for="bloqueo in ciclo.bloqueos"
                :key="bloqueo"
                class="flex items-start gap-2 text-sm text-[var(--mrl-texto)]"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0 text-[var(--mrl-rojo)]" />
                {{ bloqueo }}
            </li>
        </ul>
    </section>
</template>
