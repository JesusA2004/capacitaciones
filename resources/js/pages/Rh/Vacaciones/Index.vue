<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { CalendarDays, FileSpreadsheet, FileText } from '@lucide/vue';
import { ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useFiltros } from '@/composables/useFiltros';
import {
    aprobar,
    exportarExcel,
    exportarPdf,
    index,
    rechazar,
} from '@/routes/rh/vacaciones';
import type {
    OpcionSimple,
    RespuestaPaginada,
    SolicitudVacacionesItem,
} from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudVacacionesItem>;
    filtros: {
        estado?: string;
        empresa_id?: string;
        sucursal_id?: string;
        revisado_por?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    opciones: {
        empresas: OpcionSimple[];
        sucursales: (OpcionSimple & { empresa_id: number | null })[];
        responsables: { id: number; name: string; apellidos: string | null }[];
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Vacaciones (revisión)', href: '' }],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        estado: props.filtros.estado ?? 'pendiente',
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        revisado_por: props.filtros.revisado_por ?? '',
        busqueda: props.filtros.busqueda ?? '',
        fecha_inicio: props.filtros.fecha_inicio ?? '',
        fecha_fin: props.filtros.fecha_fin ?? '',
    },
);
const filtroSheetAbierto = ref(false);
function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

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
        >
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarExcel)">
                    <FileSpreadsheet class="size-4" />
                    Excel
                </a>
            </Button>
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarPdf)">
                    <FileText class="size-4" />
                    PDF
                </a>
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por colaborador..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
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

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Empresa, sucursal, responsable y rango de fechas."
                :contador-activos="
                    [
                        filtros.empresa_id,
                        filtros.sucursal_id,
                        filtros.revisado_por,
                        filtros.fecha_inicio,
                        filtros.fecha_fin,
                    ].filter(Boolean).length
                "
                :open="filtroSheetAbierto"
                @update:open="(v) => (filtroSheetAbierto = v)"
                @aplicar="aplicar"
                @limpiar="limpiar"
            >
                <div class="grid gap-2">
                    <Label>Empresa</Label>
                    <Select
                        :model-value="filtros.empresa_id"
                        @update:model-value="
                            (v) => (filtros.empresa_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.empresas"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Sucursal</Label>
                    <Select
                        :model-value="filtros.sucursal_id"
                        @update:model-value="
                            (v) => (filtros.sucursal_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.sucursales"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Responsable RH</Label>
                    <Select
                        :model-value="filtros.revisado_por"
                        @update:model-value="
                            (v) => (filtros.revisado_por = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.responsables"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.name }}
                                {{ opcion.apellidos }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Hasta</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_fin"
                            @update:model-value="
                                (v) => (filtros.fecha_fin = String(v ?? ''))
                            "
                        />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

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
