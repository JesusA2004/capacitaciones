<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Ban, Check, Circle, Clock } from '@lucide/vue';
import { show as verAlta } from '@/routes/rh/altas';
import { show as verInvitacion } from '@/routes/rh/incorporacion/invitaciones';
import type { CandidatoDetalle, CandidatoTimelineEtapa } from '@/types';

const props = defineProps<{
    etapas: CandidatoTimelineEtapa[];
    candidato: CandidatoDetalle;
}>();

function fechaCorta(fecha: string | null): string | null {
    return fecha ? new Date(fecha).toLocaleDateString('es-MX') : null;
}

function enlace(etapa: CandidatoTimelineEtapa): string | null {
    if (etapa.clave === 'alta_digital' && props.candidato.alta_digital) {
        return verAlta.url(props.candidato.alta_digital.id);
    }

    if (
        etapa.clave === 'qr_incorporacion' &&
        props.candidato.incorporacion_invitacion
    ) {
        return verInvitacion.url(props.candidato.incorporacion_invitacion.id);
    }

    return null;
}
</script>

<template>
    <ol class="flex flex-col gap-0">
        <li
            v-for="(etapa, indice) in etapas"
            :key="etapa.clave"
            class="relative flex gap-3 pb-6 last:pb-0"
        >
            <div class="flex flex-col items-center">
                <span
                    class="flex size-7 shrink-0 items-center justify-center rounded-full"
                    :class="{
                        'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400':
                            etapa.estado === 'completado',
                        'bg-[var(--brand-primary)]/15 text-[var(--brand-primary)] ring-2 ring-[var(--brand-primary)]/40':
                            etapa.estado === 'actual',
                        'bg-muted text-muted-foreground':
                            etapa.estado === 'pendiente',
                        'bg-destructive/15 text-destructive':
                            etapa.estado === 'descartado',
                    }"
                >
                    <Check v-if="etapa.estado === 'completado'" class="size-4" />
                    <Clock v-else-if="etapa.estado === 'actual'" class="size-4" />
                    <Ban v-else-if="etapa.estado === 'descartado'" class="size-4" />
                    <Circle v-else class="size-3" />
                </span>
                <span
                    v-if="indice < etapas.length - 1"
                    class="w-px flex-1"
                    :class="
                        etapa.estado === 'completado'
                            ? 'bg-emerald-500/40'
                            : 'bg-border'
                    "
                />
            </div>

            <div class="flex min-w-0 flex-1 flex-col gap-0.5 pt-0.5">
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="text-sm font-medium"
                        :class="{
                            'text-muted-foreground': etapa.estado === 'pendiente',
                        }"
                        >{{ etapa.titulo }}</span
                    >
                    <Link
                        v-if="enlace(etapa)"
                        :href="enlace(etapa)!"
                        class="text-xs text-[var(--brand-primary)] hover:underline"
                        >Ver detalle</Link
                    >
                </div>
                <p
                    v-if="etapa.responsable || etapa.fecha"
                    class="text-xs text-muted-foreground"
                >
                    <template v-if="etapa.responsable">{{
                        etapa.responsable
                    }}</template>
                    <template v-if="etapa.responsable && etapa.fecha">
                        ·
                    </template>
                    <template v-if="etapa.fecha">{{
                        fechaCorta(etapa.fecha)
                    }}</template>
                </p>
                <p
                    v-if="etapa.accion"
                    class="text-xs font-medium text-amber-600 dark:text-amber-400"
                >
                    Siguiente paso: {{ etapa.accion }}
                </p>
            </div>
        </li>
    </ol>
</template>
