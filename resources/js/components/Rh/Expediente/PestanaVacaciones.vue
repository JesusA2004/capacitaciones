<script setup lang="ts">
import {
    Calendar,
    CalendarCheck,
    CheckCircle2,
    Clock,
    Hourglass,
    Sparkles,
} from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import VacacionesAntiguedad from '@/components/Vacaciones/VacacionesAntiguedad.vue';
import { formatearFecha } from '@/lib/fechas';
import type {
    SaldoVacaciones,
    SolicitudExpedienteItem,
    SolicitudVacacionesItem,
} from '@/types';

defineProps<{
    saldoVacaciones: SaldoVacaciones;
    solicitudes: SolicitudExpedienteItem[];
    solicitudesVacaciones: SolicitudVacacionesItem[];
}>();
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader>
            <CardTitle class="flex items-center gap-2 text-base">
                <Calendar class="size-4" />
                Vacaciones
            </CardTitle>
            <p class="flex items-center gap-1.5 text-xs text-muted-foreground">
                <Clock class="size-3.5" />
                {{ saldoVacaciones.antiguedad_anios }}
                {{ saldoVacaciones.antiguedad_anios === 1 ? 'año' : 'años' }}
                de antigüedad
            </p>
        </CardHeader>
        <CardContent class="flex flex-col gap-4">
            <VacacionesAntiguedad :saldo="saldoVacaciones" />
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div
                    class="group rounded-xl border p-3 text-center transicion-ui hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                >
                    <CalendarCheck
                        class="mx-auto mb-1 size-4 text-primary transition-transform group-hover:scale-110"
                    />
                    <p class="text-lg font-semibold">
                        {{ saldoVacaciones.dias_generados }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Te corresponden este año
                    </p>
                </div>
                <div
                    class="group rounded-xl border p-3 text-center transicion-ui hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                >
                    <CheckCircle2
                        class="mx-auto mb-1 size-4 text-[var(--success)] transition-transform group-hover:scale-110"
                    />
                    <p class="text-lg font-semibold">
                        {{ saldoVacaciones.dias_usados_total }}
                    </p>
                    <p class="text-xs text-muted-foreground">Usados</p>
                    <p
                        v-if="saldoVacaciones.dias_usados_historicos > 0"
                        class="text-[11px] text-muted-foreground"
                    >
                        {{ saldoVacaciones.dias_usados_people }}
                        en PEOPLE +
                        {{ saldoVacaciones.dias_usados_historicos }}
                        históricos
                    </p>
                </div>
                <div
                    class="group rounded-xl border p-3 text-center transicion-ui hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                >
                    <Hourglass
                        class="mx-auto mb-1 size-4 text-warning transition-transform group-hover:scale-110"
                    />
                    <p class="text-lg font-semibold">
                        {{ saldoVacaciones.dias_en_solicitud }}
                    </p>
                    <p class="text-xs text-muted-foreground">En solicitud</p>
                </div>
                <div
                    class="group rounded-xl border p-3 text-center transicion-ui hover:-translate-y-0.5 hover:border-primary/40 hover:shadow-md"
                >
                    <Sparkles
                        class="mx-auto mb-1 size-4 text-[var(--success)] transition-transform group-hover:scale-110"
                    />
                    <p class="text-lg font-semibold">
                        {{ saldoVacaciones.dias_disponibles }}
                    </p>
                    <p class="text-xs text-muted-foreground">Disponibles</p>
                </div>
            </div>

            <div class="flex flex-col gap-2">
                <p
                    v-for="solicitud in solicitudesVacaciones"
                    :key="solicitud.id"
                    class="flex items-center justify-between rounded-lg border p-2 text-sm"
                >
                    <span
                        >{{ formatearFecha(solicitud.fecha_inicio) }}
                        —
                        {{ formatearFecha(solicitud.fecha_fin) }}
                        ({{ solicitud.dias_solicitados }} días)</span
                    >
                    <EstadoBadge :estado="solicitud.estado" />
                </p>
                <p
                    v-if="!solicitudesVacaciones.length"
                    class="text-sm text-muted-foreground"
                >
                    Sin solicitudes de vacaciones registradas.
                </p>
            </div>
        </CardContent>
    </Card>
</template>
