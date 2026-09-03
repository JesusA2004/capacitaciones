<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ClipboardList, FileSpreadsheet, FileText } from '@lucide/vue';
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
import { useFiltros } from '@/composables/useFiltros';
import {
    exportarExcel,
    exportarPdf,
    index,
    show,
} from '@/routes/rh/solicitudes';
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
        departamento_id?: string;
        puesto_id?: string;
        revisado_por?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    tipos: TipoSolicitudInterna[];
    opciones: OpcionesSolicitudes;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes (revisión)', href: '' }],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        estado: props.filtros.estado ?? '',
        tipo: props.filtros.tipo ?? '',
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
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
                placeholder="Buscar por folio o motivo..."
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

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Departamento, puesto, responsable y fecha de registro."
                :contador-activos="
                    [
                        filtros.departamento_id,
                        filtros.puesto_id,
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
                    <Label>Departamento</Label>
                    <Select
                        :model-value="filtros.departamento_id"
                        @update:model-value="
                            (v) => (filtros.departamento_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.departamentos"
                                :key="opcion.id"
                                :value="String(opcion.id)"
                                >{{ opcion.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label>Puesto</Label>
                    <Select
                        :model-value="filtros.puesto_id"
                        @update:model-value="
                            (v) => (filtros.puesto_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.puestos"
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
                        <Label>Registrada desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Registrada hasta</Label>
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
