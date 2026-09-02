<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CalendarDays } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useFiltros } from '@/composables/useFiltros';
import { aprobar, index, rechazar } from '@/routes/rh/vacaciones';
import type { RespuestaPaginada, SolicitudVacacionesItem } from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudVacacionesItem>;
    filtros: { estado?: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vacaciones (revisión)', href: '' }],
    },
});

const { filtros, aplicar } = useFiltros(index.url(), {
    estado: props.filtros.estado ?? 'pendiente',
});

const formAprobar = useForm({});
function aprobarSolicitud(solicitud: SolicitudVacacionesItem) {
    formAprobar.post(aprobar.url(solicitud.id), { preserveScroll: true });
}

const solicitudRechazando = ref<number | null>(null);
const formRechazar = useForm({ motivo_rechazo: '' });
function rechazarSolicitud(solicitud: SolicitudVacacionesItem) {
    formRechazar.post(rechazar.url(solicitud.id), {
        preserveScroll: true,
        onSuccess: () => {
            solicitudRechazando.value = null;
            formRechazar.reset();
        },
    });
}
</script>

<template>
    <Head title="Revisión de vacaciones" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Vacaciones"
            descripcion="Revisa y aprueba las solicitudes de vacaciones de tu equipo."
            :icono="CalendarDays"
        />

        <Select
            :model-value="filtros.estado"
            @update:model-value="
                (v) => {
                    filtros.estado = String(v ?? '');
                    aplicar();
                }
            "
        >
            <SelectTrigger class="w-56"
                ><SelectValue placeholder="Todos los estados"
            /></SelectTrigger>
            <SelectContent>
                <SelectItem value="pendiente">Pendiente</SelectItem>
                <SelectItem value="aprobada">Aprobada</SelectItem>
                <SelectItem value="rechazada">Rechazada</SelectItem>
                <SelectItem value="cancelada">Cancelada</SelectItem>
            </SelectContent>
        </Select>

        <CrudEmptyState
            v-if="!solicitudes.data.length"
            :icono="CalendarDays"
            titulo="Sin solicitudes"
            descripcion="No hay solicitudes de vacaciones con este filtro."
        />

        <div v-else class="flex flex-col gap-3">
            <div
                v-for="solicitud in solicitudes.data"
                :key="solicitud.id"
                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <p class="text-sm font-medium">
                            {{ solicitud.usuario?.name }}
                            {{ solicitud.usuario?.apellidos }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ solicitud.fecha_inicio }} —
                            {{ solicitud.fecha_fin }} ({{
                                solicitud.dias_solicitados
                            }}
                            días)
                        </p>
                    </div>
                    <EstadoBadge :estado="solicitud.estado" />
                </div>
                <p v-if="solicitud.comentario" class="text-sm">
                    {{ solicitud.comentario }}
                </p>

                <div
                    v-if="solicitud.estado === 'pendiente'"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Button size="sm" @click="aprobarSolicitud(solicitud)"
                        >Aprobar</Button
                    >
                    <Button
                        v-if="solicitudRechazando !== solicitud.id"
                        size="sm"
                        variant="destructive"
                        @click="solicitudRechazando = solicitud.id"
                        >Rechazar</Button
                    >
                    <template v-else>
                        <Textarea
                            v-model="formRechazar.motivo_rechazo"
                            placeholder="Motivo del rechazo"
                            class="w-64"
                            rows="1"
                        />
                        <Button
                            size="sm"
                            variant="destructive"
                            :disabled="
                                formRechazar.processing ||
                                !formRechazar.motivo_rechazo
                            "
                            @click="rechazarSolicitud(solicitud)"
                            >Confirmar rechazo</Button
                        >
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
