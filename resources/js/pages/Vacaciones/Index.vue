<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarDays, Plus } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { cancelar, index, store } from '@/routes/vacaciones';
import type { SaldoVacaciones, SolicitudVacacionesItem } from '@/types';

defineProps<{
    saldo: SaldoVacaciones;
    solicitudes: SolicitudVacacionesItem[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Vacaciones', href: index.url() },
        ],
    },
});

const { confirmarEliminacion, mostrarExito } = useAlertas();
const dialogoAbierto = ref(false);

const form = useForm({
    fecha_inicio: '',
    fecha_fin: '',
    dias_solicitados: 1,
    comentario: '',
});

function enviar() {
    form.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => {
            dialogoAbierto.value = false;
            form.reset();
        },
    });
}

async function cancelarSolicitud(solicitud: SolicitudVacacionesItem) {
    const confirmado = await confirmarEliminacion(
        'esta solicitud de vacaciones',
    );

    if (!confirmado) {
        return;
    }

    router.post(
        cancelar.url(solicitud.id),
        {},
        {
            preserveScroll: true,
            onSuccess: () => mostrarExito('Solicitud cancelada.'),
        },
    );
}
</script>

<template>
    <Head title="Vacaciones" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Vacaciones"
            descripcion="Consulta tu saldo y solicita tus días de vacaciones."
            :icono="CalendarDays"
        >
            <Button @click="dialogoAbierto = true">
                <Plus class="size-4" />
                Solicitar vacaciones
            </Button>
        </CrudPageHeader>

        <div class="grid gap-4 sm:grid-cols-4">
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">Días generados</p>
                <p class="text-2xl font-semibold">{{ saldo.dias_generados }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">Días usados</p>
                <p class="text-2xl font-semibold">{{ saldo.dias_usados }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">En solicitud</p>
                <p class="text-2xl font-semibold">
                    {{ saldo.dias_en_solicitud }}
                </p>
            </div>
            <div
                class="rounded-2xl border border-border/60 bg-card p-4 ring-1 ring-[var(--brand-primary)]/30"
            >
                <p class="text-xs text-muted-foreground">Disponibles</p>
                <p class="text-2xl font-semibold text-[var(--brand-primary)]">
                    {{ saldo.dias_disponibles }}
                </p>
            </div>
        </div>

        <p v-if="saldo.vigencia_inicio" class="text-xs text-muted-foreground">
            Vigencia actual: {{ saldo.vigencia_inicio }} a
            {{ saldo.vigencia_fin }} (año {{ saldo.antiguedad_anios }} de
            antigüedad).
        </p>

        <div class="flex flex-col gap-2">
            <h2 class="text-sm font-semibold">Mis solicitudes</h2>
            <div
                v-for="solicitud in solicitudes"
                :key="solicitud.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-border/60 bg-card p-3 text-sm"
            >
                <div>
                    <p class="font-medium">
                        {{ solicitud.fecha_inicio }} —
                        {{ solicitud.fecha_fin }} ({{
                            solicitud.dias_solicitados
                        }}
                        días)
                    </p>
                    <p
                        v-if="solicitud.motivo_rechazo"
                        class="text-xs text-destructive"
                    >
                        Rechazada: {{ solicitud.motivo_rechazo }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <EstadoBadge :estado="solicitud.estado" />
                    <Button
                        v-if="solicitud.estado === 'pendiente'"
                        size="sm"
                        variant="outline"
                        @click="cancelarSolicitud(solicitud)"
                        >Cancelar</Button
                    >
                </div>
            </div>
            <p
                v-if="!solicitudes.length"
                class="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground"
            >
                Todavía no has solicitado vacaciones.
            </p>
        </div>
    </div>

    <Dialog v-model:open="dialogoAbierto">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Solicitar vacaciones</DialogTitle>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="enviar">
                <div class="grid grid-cols-2 gap-4">
                    <div class="grid gap-2">
                        <Label for="fecha_inicio">Fecha de inicio</Label>
                        <Input
                            id="fecha_inicio"
                            v-model="form.fecha_inicio"
                            type="date"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="fecha_fin">Fecha de fin</Label>
                        <Input
                            id="fecha_fin"
                            v-model="form.fecha_fin"
                            type="date"
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="dias">Días solicitados</Label>
                    <Input
                        id="dias"
                        v-model="form.dias_solicitados"
                        type="number"
                        min="1"
                    />
                    <p
                        v-if="form.errors.dias_solicitados"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.dias_solicitados }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <Label for="comentario">Comentario (opcional)</Label>
                    <Textarea
                        id="comentario"
                        v-model="form.comentario"
                        rows="2"
                    />
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="secondary"
                        @click="dialogoAbierto = false"
                        >Cancelar</Button
                    >
                    <Button type="submit" :disabled="form.processing">
                        <Spinner v-if="form.processing" />
                        Enviar solicitud
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
