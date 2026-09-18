<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    CircleDollarSign,
    ClipboardList,
    Percent,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFiltros } from '@/composables/useFiltros';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import { exportarExcel, exportarPdf, index } from '@/routes/rh/vacantes';
import type {
    OpcionesReclutamiento,
    RespuestaPaginada,
    VacanteItem,
    VacantesKpis,
} from '@/types';

const props = defineProps<{
    vacantes: VacanteItem[];
    kpis: VacantesKpis;
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        busqueda?: string;
    };
    opciones: OpcionesReclutamiento;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Vacantes', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        busqueda: props.filtros.busqueda ?? '',
    },
);

function urlExportar(destino: typeof exportarExcel | typeof exportarPdf): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

// DataTable.vue espera una respuesta paginada del servidor; este listado no
// pagina (es el universo de pares sucursal/puesto con headcount vigente,
// siempre acotado y pequeño), así que se envuelve en una sola página.
const vacantesPaginadas = computed<RespuestaPaginada<VacanteItem>>(() => ({
    data: props.vacantes,
    current_page: 1,
    last_page: 1,
    per_page: Math.max(props.vacantes.length, 1),
    total: props.vacantes.length,
    from: props.vacantes.length ? 1 : null,
    to: props.vacantes.length,
    links: [],
}));

const columnas: ColumnaDataTable[] = [
    { clave: 'sucursal', etiqueta: 'Sucursal' },
    { clave: 'departamento', etiqueta: 'Departamento' },
    { clave: 'puesto', etiqueta: 'Puesto' },
    { clave: 'plantilla_permitida', etiqueta: 'Plantilla permitida' },
    { clave: 'plantilla_cubierta', etiqueta: 'Plantilla cubierta' },
    { clave: 'vacantes_disponibles', etiqueta: 'Vacantes disponibles' },
    { clave: 'candidatos_activos', etiqueta: 'Candidatos activos' },
    { clave: 'candidatos_finalistas', etiqueta: 'Candidatos finalistas' },
    { clave: 'cobertura_pct', etiqueta: 'Cobertura %' },
    { clave: 'costo_presupuestado_mensual', etiqueta: 'Costo mensual' },
    { clave: 'fecha_apertura_mas_antigua', etiqueta: 'Faltante desde' },
];

const tarjetasKpi = computed(() => [
    {
        etiqueta: 'Sucursales bajo cobertura',
        valor: props.kpis.sucursales_bajo_cobertura,
        icono: Building2,
        tono: 'warning' as const,
    },
    {
        etiqueta: 'Plantilla permitida',
        valor: props.kpis.plantilla_permitida_total,
        icono: ClipboardList,
    },
    {
        etiqueta: 'Plantilla cubierta',
        valor: props.kpis.plantilla_cubierta_total,
        icono: Users,
        tono: 'success' as const,
    },
    {
        etiqueta: 'Vacantes disponibles',
        valor: props.kpis.vacantes_totales,
        icono: Briefcase,
        tono: props.kpis.vacantes_totales > 0 ? ('warning' as const) : ('default' as const),
    },
    {
        etiqueta: 'Cobertura global',
        valor: `${props.kpis.cobertura_pct_global}%`,
        icono: Percent,
    },
    {
        etiqueta: 'Costo mensual presupuestado',
        valor: formatoMoneda(props.kpis.costo_mensual_total),
        icono: CircleDollarSign,
    },
]);
</script>

<template>
    <Head title="Vacantes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Vacantes"
            descripcion="Cobertura de plantilla por sucursal y puesto: plazas permitidas, cubiertas y disponibles, calculadas en vivo."
            :icono="Briefcase"
        >
            <CrudExportButtons
                :url-excel="urlExportar(exportarExcel)"
                :url-pdf="urlExportar(exportarPdf)"
            />
        </CrudPageHeader>

        <CrudStats :estadisticas="tarjetasKpi" />

        <div class="flex flex-wrap items-center gap-2">
            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground">Buscar</Label>
                <input
                    :value="filtros.busqueda"
                    type="text"
                    placeholder="Buscar por sucursal, departamento o puesto..."
                    class="h-9 w-64 rounded-md border border-input bg-transparent px-3 text-sm shadow-sm outline-none focus-visible:ring-1 focus-visible:ring-ring"
                    @input="
                        (evento) => {
                            filtros.busqueda = (
                                evento.target as HTMLInputElement
                            ).value;
                            aplicarConDebounce();
                        }
                    "
                />
            </div>

            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground">Empresa</Label>
                <Select
                    :model-value="filtros.empresa_id"
                    @update:model-value="
                        (v) => {
                            filtros.empresa_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
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

            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground">Sucursal</Label>
                <Select
                    :model-value="filtros.sucursal_id"
                    @update:model-value="
                        (v) => {
                            filtros.sucursal_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
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

            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground"
                    >Departamento</Label
                >
                <Select
                    :model-value="filtros.departamento_id"
                    @update:model-value="
                        (v) => {
                            filtros.departamento_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
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

            <div class="grid gap-1.5">
                <Label class="text-xs text-muted-foreground">Puesto</Label>
                <Select
                    :model-value="filtros.puesto_id"
                    @update:model-value="
                        (v) => {
                            filtros.puesto_id = String(v ?? '');
                            aplicar();
                        }
                    "
                >
                    <SelectTrigger class="w-44"
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

            <Button variant="ghost" size="sm" class="mt-5" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="vacantesPaginadas"
            mensaje-vacio="No hay combinaciones de sucursal y puesto con plantilla configurada."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Briefcase"
                    titulo="Sin plantilla configurada"
                    descripcion="No hay combinaciones de sucursal y puesto con plantilla configurada para los filtros actuales."
                />
            </template>

            <template #celda-sucursal="{ fila }">
                <span>{{ fila.sucursal?.nombre ?? '—' }}</span>
            </template>
            <template #celda-departamento="{ fila }">
                <span class="text-muted-foreground">{{
                    fila.departamento?.nombre ?? '—'
                }}</span>
            </template>
            <template #celda-puesto="{ fila }">
                <span>{{ fila.puesto?.nombre ?? '—' }}</span>
            </template>
            <template #celda-vacantes_disponibles="{ fila }">
                <Badge
                    v-if="fila.vacantes_disponibles > 0"
                    variant="outline"
                    class="border-[var(--brand-primary)]/40 text-[var(--brand-primary)]"
                >
                    {{ fila.vacantes_disponibles }}
                </Badge>
                <span v-else class="text-muted-foreground">0</span>
            </template>
            <template #celda-cobertura_pct="{ fila }">
                <span>{{ fila.cobertura_pct }}%</span>
            </template>
            <template #celda-costo_presupuestado_mensual="{ fila }">
                <span>{{
                    fila.costo_presupuestado_mensual !== null
                        ? formatoMoneda(fila.costo_presupuestado_mensual)
                        : '—'
                }}</span>
            </template>
            <template #celda-fecha_apertura_mas_antigua="{ fila }">
                <span class="text-muted-foreground">{{
                    fila.fecha_apertura_mas_antigua ?? '—'
                }}</span>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="fila.puesto?.nombre ?? 'Sin puesto'"
                    :subtitulo="`${fila.sucursal?.nombre ?? 'Sin sucursal'} · ${fila.departamento?.nombre ?? 'Sin departamento'}`"
                >
                    <template #badge>
                        <Badge
                            v-if="fila.vacantes_disponibles > 0"
                            variant="outline"
                            class="border-[var(--brand-primary)]/40 text-[var(--brand-primary)]"
                        >
                            {{ fila.vacantes_disponibles }} disponible{{
                                fila.vacantes_disponibles === 1 ? '' : 's'
                            }}
                        </Badge>
                    </template>
                    <span
                        >Plantilla: {{ fila.plantilla_cubierta }}/{{
                            fila.plantilla_permitida
                        }}
                        ({{ fila.cobertura_pct }}%)</span
                    >
                    <span
                        >Candidatos: {{ fila.candidatos_activos }} activos ·
                        {{ fila.candidatos_finalistas }} finalistas</span
                    >
                    <span v-if="fila.costo_presupuestado_mensual !== null"
                        >Costo mensual:
                        {{
                            formatoMoneda(fila.costo_presupuestado_mensual)
                        }}</span
                    >
                </CrudMobileCard>
            </template>
        </DataTable>
    </div>
</template>
