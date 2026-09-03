<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ClipboardList } from '@lucide/vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFiltros } from '@/composables/useFiltros';
import { index, show } from '@/routes/rh/solicitudes';
import type {
    OpcionesSolicitudes,
    RespuestaPaginada,
    SolicitudInternaItem,
    TipoSolicitudInterna,
} from '@/types';

const props = defineProps<{
    solicitudes: RespuestaPaginada<SolicitudInternaItem>;
    filtros: {
        estado?: string;
        tipo?: string;
        empresa_id?: string;
        sucursal_id?: string;
    };
    tipos: TipoSolicitudInterna[];
    opciones: OpcionesSolicitudes;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes (revisión)', href: '' }],
    },
});

const { filtros, aplicar } = useFiltros(index.url(), {
    estado: props.filtros.estado ?? '',
    tipo: props.filtros.tipo ?? '',
    empresa_id: props.filtros.empresa_id ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
});

const ESTADOS = [
    'enviada',
    'en_revision',
    'requiere_correccion',
    'aprobada',
    'rechazada',
    'cancelada',
    'cerrada',
];
</script>

<template>
    <Head title="Solicitudes internas" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Solicitudes internas"
            descripcion="Permisos, incapacidades, constancias y otros trámites de los colaboradores."
            :icono="ClipboardList"
        />

        <div class="flex flex-wrap items-center gap-2">
            <Select
                :model-value="filtros.estado"
                @update:model-value="
                    (v) => {
                        filtros.estado = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todos los estados"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem v-for="e in ESTADOS" :key="e" :value="e">{{
                        e.replace(/_/g, ' ')
                    }}</SelectItem>
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.tipo"
                @update:model-value="
                    (v) => {
                        filtros.tipo = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-52"
                    ><SelectValue placeholder="Todos los tipos"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="tipo in tipos"
                        :key="tipo.value"
                        :value="tipo.value"
                        >{{ tipo.label }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.empresa_id"
                @update:model-value="
                    (v) => {
                        filtros.empresa_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todas las empresas"
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

            <Select
                :model-value="filtros.sucursal_id"
                @update:model-value="
                    (v) => {
                        filtros.sucursal_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todas las sucursales"
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

        <CrudEmptyState
            v-if="!solicitudes.data.length"
            :icono="ClipboardList"
            titulo="Sin solicitudes"
            descripcion="No hay solicitudes internas con este filtro."
        />

        <div v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <a
                v-for="solicitud in solicitudes.data"
                :key="solicitud.id"
                :href="show.url(solicitud.id)"
                class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
            >
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p
                            class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                        >
                            {{ solicitud.folio }}
                        </p>
                        <p class="text-sm font-semibold">
                            {{ solicitud.usuario?.name }}
                            {{ solicitud.usuario?.apellidos }}
                        </p>
                        <p class="text-xs text-muted-foreground capitalize">
                            {{
                                tipos.find((t) => t.value === solicitud.tipo)
                                    ?.label ?? solicitud.tipo
                            }}
                        </p>
                    </div>
                    <EstadoBadge :estado="solicitud.estado" />
                </div>
                <p class="line-clamp-2 text-sm text-muted-foreground">
                    {{ solicitud.motivo }}
                </p>
            </a>
        </div>
    </div>
</template>
