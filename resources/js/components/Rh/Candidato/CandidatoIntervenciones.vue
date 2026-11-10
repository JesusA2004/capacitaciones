<script setup lang="ts">
import { Gavel, Hand, ShieldAlert, ShieldX } from '@lucide/vue';
import SeccionFicha from '@/components/Rh/Candidato/SeccionFicha.vue';
import TextoLargo from '@/components/Rh/Candidato/TextoLargo.vue';
import { Button } from '@/components/ui/button';
import { formatearFechaHora } from '@/lib/fechas';
import type { CandidatoFicha } from '@/types';

/**
 * Historial de intervenciones sobre un rechazo de RH, contado en tres
 * pasos (rechazo → solicitud → decisión). Los botones para decidir solo
 * aparecen a quien tiene autoridad (intervencionPendienteId del backend).
 */
withDefaults(
    defineProps<{
        intervenciones: CandidatoFicha['intervenciones'];
        intervencionPendienteId: number | null;
        indice?: number;
    }>(),
    { indice: 0 },
);

const emit = defineEmits<{ decidir: [aprueba: boolean] }>();
</script>

<template>
    <SeccionFicha
        titulo="Intervención sobre el rechazo de RH"
        :icono="ShieldAlert"
        tono="alerta"
        descripcion="Un gerente pidió reconsiderar el rechazo; aquí está cada paso y quién decidió."
        :indice="indice"
    >
        <article
            v-for="item in intervenciones"
            :key="item.id"
            class="flex flex-col gap-3 rounded-2xl border border-warning/40 bg-warning-soft/40 p-3.5 text-sm"
        >
            <ol
                class="relative flex flex-col gap-3 border-l-2 border-warning/40 pl-5"
            >
                <li class="relative">
                    <span
                        class="absolute top-0 -left-[1.95rem] flex size-6 items-center justify-center rounded-full bg-danger-soft text-destructive ring-4 ring-[var(--mrl-superficie)]"
                        aria-hidden="true"
                    >
                        <ShieldX class="size-3.5" />
                    </span>
                    <p class="break-words">
                        <strong>RH rechazó</strong>
                        <template v-if="item.rechazo_rh_por">
                            · {{ item.rechazo_rh_por }}</template
                        >
                        <template v-if="item.rechazo_rh_en">
                            ·
                            {{
                                formatearFechaHora(item.rechazo_rh_en)
                            }}</template
                        >
                    </p>
                    <TextoLargo
                        v-if="item.rechazo_rh_motivo"
                        class="mt-1.5"
                        :texto="item.rechazo_rh_motivo"
                    />
                </li>
                <li class="relative">
                    <span
                        class="absolute top-0 -left-[1.95rem] flex size-6 items-center justify-center rounded-full bg-warning-soft text-warning ring-4 ring-[var(--mrl-superficie)]"
                        aria-hidden="true"
                    >
                        <Hand class="size-3.5" />
                    </span>
                    <p class="break-words">
                        <strong>Intervención solicitada por</strong>
                        {{ item.gerente_solicitante ?? '—' }} ·
                        {{ formatearFechaHora(item.solicitada_en) }} ·
                        {{ item.ruta_etiqueta }}
                    </p>
                    <TextoLargo class="mt-1.5" :texto="item.motivo_solicitud" />
                </li>
                <li class="relative">
                    <span
                        class="absolute top-0 -left-[1.95rem] flex size-6 items-center justify-center rounded-full bg-salvia text-primary ring-4 ring-[var(--mrl-superficie)]"
                        aria-hidden="true"
                    >
                        <Gavel class="size-3.5" />
                    </span>
                    <p class="break-words">
                        <strong>{{ item.estado_etiqueta }}</strong>
                        <template v-if="item.aprobador">
                            · {{ item.aprobador }} ·
                            {{ formatearFechaHora(item.decidida_en) }}</template
                        >
                    </p>
                    <TextoLargo
                        v-if="item.comentario_decision"
                        class="mt-1.5"
                        :texto="item.comentario_decision"
                    />
                </li>
            </ol>

            <div
                v-if="intervencionPendienteId === item.id"
                class="flex flex-wrap gap-2"
            >
                <Button size="sm" @click="emit('decidir', true)"
                    >Aprobar intervención</Button
                >
                <Button
                    size="sm"
                    variant="destructive"
                    @click="emit('decidir', false)"
                    >Confirmar rechazo</Button
                >
            </div>
        </article>
    </SeccionFicha>
</template>
