<script setup lang="ts">
import { AlertTriangle, ArrowRight, CheckCircle2 } from '@lucide/vue';
import { formatearFecha } from '@/lib/fechas';
import type { EstadoCiclo } from '@/types';

/**
 * «Dónde está y qué sigue»: estado actual → siguiente acción → botón (el
 * slot recibe las acciones, con UNA primaria). El responsable y el avance
 * quedan como contexto discreto; los bloqueos, a la vista.
 */
defineProps<{ ciclo: EstadoCiclo }>();

function fecha(valor: string | null): string {
    if (!valor) {
        return '—';
    }

    // Fecha de calendario (p. ej. la baja, "2026-10-16"): se lee tal cual;
    // new Date() la tomaría como medianoche UTC y en México saldría un día antes.
    if (/^\d{4}-\d{2}-\d{2}$/.test(valor)) {
        return formatearFecha(valor);
    }

    return new Date(valor).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}
</script>

<template>
    <section
        class="flex flex-col gap-4 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Estado actual y siguiente acción"
    >
        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm">
            <span class="text-[var(--mrl-texto-suave)]">Estado actual</span>
            <span class="font-semibold text-[var(--mrl-texto)]">{{
                ciclo.estado.etiqueta
            }}</span>
            <span class="ml-auto text-xs text-[var(--mrl-texto-suave)]"
                >{{ ciclo.etapa.etiqueta }} · desde
                {{ fecha(ciclo.fecha_desde_estado) }}</span
            >
        </div>

        <div
            class="flex flex-col gap-3 rounded-xl bg-[var(--mrl-fondo)] p-4"
            aria-live="polite"
        >
            <p class="flex items-start gap-2">
                <component
                    :is="ciclo.siguiente_accion ? ArrowRight : CheckCircle2"
                    class="mt-0.5 size-5 shrink-0"
                    :class="
                        ciclo.siguiente_accion
                            ? 'text-[var(--mrl-dorado)]'
                            : 'text-success'
                    "
                />
                <span>
                    <span
                        class="block text-xs text-[var(--mrl-texto-suave)]"
                        >Siguiente</span
                    >
                    <span
                        class="text-base font-semibold text-[var(--mrl-texto)]"
                        >{{
                            ciclo.siguiente_accion?.etiqueta ??
                            'No hay nada pendiente aquí'
                        }}</span
                    >
                </span>
            </p>
            <slot />
        </div>

        <div class="flex flex-col gap-1.5">
            <div
                class="h-1.5 overflow-hidden rounded-full bg-[var(--mrl-fondo)]"
                role="progressbar"
                :aria-valuenow="ciclo.progreso"
                aria-valuemin="0"
                aria-valuemax="100"
                aria-label="Avance del proceso"
            >
                <div
                    class="h-full rounded-full bg-[var(--mrl-petroleo)] transition-[width] duration-300 motion-reduce:transition-none"
                    :style="{ width: `${ciclo.progreso}%` }"
                />
            </div>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Responsable: {{ ciclo.responsable_actual.rol
                }}<template v-if="ciclo.responsable_actual.nombre">
                    · {{ ciclo.responsable_actual.nombre }}</template
                >
                · {{ ciclo.progreso }}% del proceso
            </p>
        </div>

        <ul
            v-if="ciclo.bloqueos.length"
            class="flex flex-col gap-1.5 rounded-xl border border-[var(--mrl-rojo)]/25 bg-[var(--mrl-rojo)]/5 p-3"
        >
            <li
                v-for="bloqueo in ciclo.bloqueos"
                :key="bloqueo"
                class="flex items-start gap-2 text-sm text-[var(--mrl-texto)]"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0 text-[var(--mrl-rojo)]"
                />
                {{ bloqueo }}
            </li>
        </ul>
    </section>
</template>
