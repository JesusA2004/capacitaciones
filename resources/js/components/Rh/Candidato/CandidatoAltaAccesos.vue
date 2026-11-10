<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import {
    CalendarClock,
    CircleCheck,
    KeyRound,
    MonitorSmartphone,
    QrCode,
    ServerCog,
} from '@lucide/vue';
import { ref } from 'vue';
import AvisoAltaSistemas from '@/components/Rh/AvisoAltaSistemas.vue';
import type { ResumenAvisoSistemas } from '@/components/Rh/AvisoAltaSistemas.vue';
import SeccionFicha from '@/components/Rh/Candidato/SeccionFicha.vue';
import GenerarCredencialesDialog from '@/components/Rh/GenerarCredencialesDialog.vue';
import { Button } from '@/components/ui/button';
import { formatearFechaHora } from '@/lib/fechas';
import { show as verInvitacion } from '@/routes/rh/incorporacion/invitaciones';
import type { CandidatoFicha } from '@/types';

/**
 * Todo lo que necesita Sistemas y RH para darle acceso a la persona: el
 * correo «Alta para asignar usuarios», el QR de contratación y las
 * credenciales sin QR. Cada parte solo aparece si el backend la manda
 * (permisos y momento del proceso los decide el servidor).
 */
withDefaults(
    defineProps<{
        candidato: CandidatoFicha;
        avisoSistemas?: ResumenAvisoSistemas | null;
        indice?: number;
    }>(),
    { avisoSistemas: null, indice: 0 },
);

// «Generar credenciales (sin QR)» (altas dadas en la web sin el registro por QR).
const dialogoCredenciales = ref(false);
</script>

<template>
    <SeccionFicha
        titulo="Alta en sistemas y accesos"
        :icono="ServerCog"
        tono="oro"
        descripcion="Aviso a Sistemas, QR de contratación y usuario de la app."
        :indice="indice"
    >
        <div class="grid gap-3 lg:grid-cols-2">
            <div
                v-if="avisoSistemas"
                class="flex min-w-0 flex-col gap-2 lg:col-span-2"
            >
                <h3
                    class="flex items-center gap-1.5 text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    <MonitorSmartphone class="size-3.5" aria-hidden="true" />
                    Aviso a Sistemas
                </h3>
                <AvisoAltaSistemas
                    :candidato-id="candidato.id"
                    :aviso="avisoSistemas"
                />
            </div>

            <div
                v-if="candidato.invitacion"
                class="flex min-w-0 flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3.5 text-sm"
            >
                <div class="flex items-center gap-2.5">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-primary"
                        aria-hidden="true"
                    >
                        <QrCode class="size-4" />
                    </span>
                    <p class="min-w-0 flex-1 font-semibold">
                        QR de contratación
                    </p>
                    <span
                        class="rounded-full bg-crema px-2.5 py-0.5 text-xs font-semibold text-bronce"
                        >{{ candidato.invitacion.estado_etiqueta }}</span
                    >
                </div>
                <ul class="flex flex-col gap-1 text-xs text-muted-foreground">
                    <li class="flex items-center gap-1.5">
                        <CalendarClock class="size-3.5" aria-hidden="true" />
                        Vence
                        {{ formatearFechaHora(candidato.invitacion.expira_en) }}
                    </li>
                    <li
                        v-if="candidato.invitacion.usada_en"
                        class="flex items-center gap-1.5"
                    >
                        <CircleCheck
                            class="size-3.5 text-success"
                            aria-hidden="true"
                        />
                        Usado
                        {{ formatearFechaHora(candidato.invitacion.usada_en) }}
                    </li>
                </ul>
                <Button as-child size="sm" variant="outline" class="self-start">
                    <Link :href="verInvitacion.url(candidato.invitacion.id)"
                        ><QrCode class="size-4" /> Ver / regenerar QR</Link
                    >
                </Button>
            </div>

            <div
                v-if="candidato.credenciales"
                class="flex min-w-0 flex-col gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-fondo)] p-3.5 text-sm"
            >
                <div class="flex items-center gap-2.5">
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-crema text-bronce"
                        aria-hidden="true"
                    >
                        <KeyRound class="size-4" />
                    </span>
                    <p class="min-w-0 flex-1 font-semibold">
                        Usuario de la app
                    </p>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                        :class="
                            candidato.credenciales.tiene_cuenta
                                ? 'bg-success-soft text-success'
                                : 'bg-warning-soft text-warning'
                        "
                        >{{
                            candidato.credenciales.tiene_cuenta
                                ? 'Con cuenta'
                                : 'Sin cuenta'
                        }}</span
                    >
                </div>
                <p
                    v-if="!candidato.credenciales.tiene_cuenta"
                    class="text-xs leading-relaxed text-muted-foreground"
                >
                    Si ya diste el alta en la web sin el QR: genera su usuario y
                    contraseña temporal. El QR pendiente se revoca y la persona
                    completa sus datos faltantes en la app.
                </p>
                <Button
                    size="sm"
                    variant="outline"
                    class="self-start"
                    @click="dialogoCredenciales = true"
                >
                    <KeyRound class="size-4" />
                    {{
                        candidato.credenciales.tiene_cuenta
                            ? 'Nueva contraseña temporal'
                            : 'Generar credenciales (sin QR)'
                    }}
                </Button>
                <GenerarCredencialesDialog
                    v-model:open="dialogoCredenciales"
                    :colaborador-id="candidato.credenciales.colaborador_id"
                    :colaborador-nombre="
                        candidato.credenciales.colaborador_nombre
                    "
                    :tiene-cuenta="candidato.credenciales.tiene_cuenta"
                    @generadas="router.reload({ only: ['candidato'] })"
                />
            </div>
        </div>
    </SeccionFicha>
</template>
