<script setup lang="ts">
import { BadgeCheck, Clock, ShieldCheck, XCircle } from '@lucide/vue';
import type { AprobacionItem, ResumenAprobaciones } from '@/types';

defineProps<{ aprobaciones: ResumenAprobaciones | null }>();

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleString('es-MX', { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

function color(item: AprobacionItem | null): string {
    switch (item?.estado) {
        case 'aprobado':
            return 'text-[var(--mrl-verde)]';
        case 'rechazado':
            return 'text-[var(--mrl-rojo)]';
        case 'devuelto':
            return 'text-[var(--mrl-dorado-oscuro)]';
        default:
            return 'text-[var(--mrl-texto-suave)]';
    }
}
</script>

<template>
    <section
        class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        aria-label="Aprobaciones"
    >
        <h2 class="mb-3 flex items-center gap-2 text-sm font-semibold text-[var(--mrl-texto)]">
            <ShieldCheck class="size-4 text-[var(--mrl-petroleo)]" />
            Aprobaciones
        </h2>

        <p v-if="!aprobaciones || aprobaciones.ronda === 0" class="text-sm text-[var(--mrl-texto-suave)]">
            Aún no hay aprobaciones en este proceso.
        </p>

        <div v-else class="flex flex-col gap-3">
            <div
                v-for="(item, etiqueta) in {
                    'Preautorización operativa': aprobaciones.preautorizacion,
                    'Autorización final de RH': aprobaciones.autorizacion_rh,
                }"
                :key="etiqueta"
                class="flex items-start gap-3 rounded-xl bg-[var(--mrl-fondo)] p-3"
            >
                <BadgeCheck v-if="item?.estado === 'aprobado'" class="mt-0.5 size-5 shrink-0" :class="color(item)" />
                <XCircle v-else-if="item?.estado === 'rechazado'" class="mt-0.5 size-5 shrink-0" :class="color(item)" />
                <Clock v-else class="mt-0.5 size-5 shrink-0" :class="color(item)" />
                <div class="min-w-0 text-sm">
                    <p class="font-medium text-[var(--mrl-texto)]">{{ etiqueta }}</p>
                    <p v-if="!item" class="text-[var(--mrl-texto-suave)]">Todavía no se abre.</p>
                    <template v-else>
                        <p :class="color(item)">
                            {{ item.estado_etiqueta }}
                            <template v-if="item.decidido_por"> por {{ item.decidido_por }}</template>
                            <template v-else-if="item.estado === 'pendiente' && item.aprobador">
                                · espera a {{ item.aprobador }}</template
                            >
                        </p>
                        <p v-if="item.decidido_en" class="text-xs text-[var(--mrl-texto-suave)]">
                            {{ fecha(item.decidido_en) }}
                        </p>
                        <p v-if="item.comentario" class="mt-1 text-[var(--mrl-texto)]">“{{ item.comentario }}”</p>
                    </template>
                </div>
            </div>

            <p v-if="aprobaciones.ronda > 1" class="text-xs text-[var(--mrl-texto-suave)]">
                Ronda {{ aprobaciones.ronda }}: las rondas anteriores se devolvieron para corrección y se
                conservan en la línea de tiempo.
            </p>
        </div>
    </section>
</template>
