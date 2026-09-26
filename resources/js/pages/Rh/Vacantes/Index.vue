<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Briefcase, CalendarClock, CircleDollarSign, FilterX, MapPin, UserSearch, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { usePermisos } from '@/composables/usePermisos';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as showSucursal } from '@/routes/administracion/sucursales';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { exportarExcel, exportarPdf, index } from '@/routes/rh/vacantes';
import type { VacanteItem, VacantesKpis } from '@/types';

/**
 * Vacantes: QUÉ puestos faltan por cubrir y dónde (una fila por vacante
 * real), no la plantilla por sucursal — esa vive en el detalle de cada
 * sucursal (Administración > Sucursales). Las vacantes se abren y cierran
 * solas con headcount (docs/HEADCOUNT_Y_VACANTES.md).
 */
type Opcion = { id: number; nombre: string };

const props = defineProps<{
    vacantes: VacanteItem[];
    kpis: VacantesKpis;
    filtros: { busqueda?: string; sucursal_id?: string; puesto_id?: string; departamento_id?: string; estado?: string };
    opciones: { sucursales: Opcion[]; departamentos: Opcion[]; puestos: Opcion[]; estados: { valor: string; etiqueta: string }[] };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Vacantes', href: index.url() },
        ],
    },
});

const { tienePermiso } = usePermisos();
const busqueda = ref(props.filtros.busqueda ?? '');
const borrador = ref({
    sucursal_id: props.filtros.sucursal_id ?? '',
    puesto_id: props.filtros.puesto_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    estado: props.filtros.estado ?? '',
});
const sheetAbierto = ref(false);
let temporizador: ReturnType<typeof setTimeout> | undefined;

const activos = computed(() => Object.values({ ...props.filtros, busqueda: undefined }).filter(Boolean).length);

function parametros(extra: Record<string, string> = {}) {
    return Object.fromEntries(Object.entries({ busqueda: busqueda.value, ...borrador.value, ...extra }).filter(([, v]) => v));
}

function navegar() {
    router.get(index.url(), parametros(), { preserveState: true, preserveScroll: true, replace: true });
}

watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(navegar, 350);
});

function limpiar() {
    busqueda.value = '';
    borrador.value = { sucursal_id: '', puesto_id: '', departamento_id: '', estado: '' };
    navegar();
}

function urlExportar(destino: typeof exportarExcel | typeof exportarPdf): string {
    return `${destino.url()}?${new URLSearchParams(parametros()).toString()}`;
}

const estadisticas = computed(() => [
    { etiqueta: 'Vacantes abiertas', valor: props.kpis.vacantes_abiertas, icono: Briefcase, tono: props.kpis.vacantes_abiertas > 0 ? ('warning' as const) : undefined },
    { etiqueta: 'Plazas por cubrir', valor: props.kpis.plazas_disponibles, icono: Users },
    { etiqueta: 'Candidatos en proceso', valor: props.kpis.candidatos_activos, icono: UserSearch, tono: 'info' as const },
    { etiqueta: 'Días promedio abiertas', valor: props.kpis.dias_promedio_abierta, icono: CalendarClock },
    { etiqueta: 'Costo mensual de las plazas', valor: formatoMoneda(props.kpis.costo_mensual), icono: CircleDollarSign },
]);

function fecha(valor: string): string {
    return new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' });
}

const TONO_ESTADO: Record<string, string> = {
    abierta: 'border-[var(--warning)]/40 text-[var(--warning)]',
    en_reclutamiento: 'border-[var(--brand-secondary)]/40 text-[var(--brand-secondary)]',
    con_candidatos: 'border-[var(--brand-secondary)]/40 text-[var(--brand-secondary)]',
    en_revision: 'border-[var(--brand-secondary)]/40 text-[var(--brand-secondary)]',
    cubierta: 'border-[var(--success)]/40 text-[var(--success)]',
    cancelada: 'text-muted-foreground',
};
</script>

<template>
    <Head title="Vacantes" />

    <div class="mx-auto flex w-full max-w-screen-2xl min-w-0 flex-col gap-4 p-3 sm:p-4 lg:px-6">
        <CrudPageHeader titulo="Vacantes" :icono="Briefcase">
            <CrudExportButtons :url-excel="urlExportar(exportarExcel)" :url-pdf="urlExportar(exportarPdf)" />
        </CrudPageHeader>

        <CrudStats :estadisticas="estadisticas" />

        <div data-tour="vacantes-filtros" class="flex min-w-0 items-center gap-2" role="search">
            <CrudSearchInput v-model="busqueda" placeholder="Buscar puesto o sucursal…" class="min-w-0 flex-1 sm:w-72 sm:flex-none" />
            <CrudFilterSheet v-model:open="sheetAbierto" :contador-activos="activos" @aplicar="navegar" @limpiar="limpiar">
                <div class="grid gap-1.5">
                    <Label for="f-estado">Estado</Label>
                    <NativeSelect id="f-estado" v-model="borrador.estado" class="w-full">
                        <option value="">Activas (sin cubiertas ni canceladas)</option>
                        <option v-for="e in opciones.estados" :key="e.valor" :value="e.valor">{{ e.etiqueta }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-sucursal">Sucursal</Label>
                    <NativeSelect id="f-sucursal" v-model="borrador.sucursal_id" class="w-full">
                        <option value="">Todas</option>
                        <option v-for="s in opciones.sucursales" :key="s.id" :value="String(s.id)">{{ s.nombre }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-puesto">Puesto</Label>
                    <NativeSelect id="f-puesto" v-model="borrador.puesto_id" class="w-full">
                        <option value="">Todos</option>
                        <option v-for="p in opciones.puestos" :key="p.id" :value="String(p.id)">{{ p.nombre }}</option>
                    </NativeSelect>
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-departamento">Departamento</Label>
                    <NativeSelect id="f-departamento" v-model="borrador.departamento_id" class="w-full">
                        <option value="">Todos</option>
                        <option v-for="d in opciones.departamentos" :key="d.id" :value="String(d.id)">{{ d.nombre }}</option>
                    </NativeSelect>
                </div>
            </CrudFilterSheet>
            <Button v-if="activos > 0 || filtros.busqueda" variant="ghost" size="icon-sm" aria-label="Limpiar filtros" title="Limpiar filtros" @click="limpiar">
                <FilterX class="size-4" />
            </Button>
        </div>

        <CrudEmptyState
            v-if="vacantes.length === 0"
            :icono="Briefcase"
            titulo="No hay vacantes con estos filtros"
            descripcion="Las vacantes se abren solas cuando una sucursal tiene menos personas que su plantilla autorizada."
        />

        <!-- Una fila por vacante: puesto primero (es lo que se busca cubrir). -->
        <ul v-else class="@container divide-y rounded-xl border bg-card" aria-label="Vacantes">
            <li
                v-for="vacante in vacantes"
                :key="vacante.id"
                class="grid gap-3 p-4 @3xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_auto] @3xl:items-center"
            >
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-semibold">
                        {{ vacante.puesto ?? 'Puesto sin definir' }}
                        <Badge variant="outline" :class="TONO_ESTADO[vacante.estado]">{{ vacante.estado_etiqueta }}</Badge>
                        <Badge v-if="!vacante.generada_automaticamente" variant="secondary">Manual</Badge>
                    </p>
                    <p class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground">
                        <MapPin class="size-3.5 shrink-0" />
                        <Link
                            v-if="vacante.sucursal_id && tienePermiso('sucursales.administrar')"
                            :href="showSucursal.url(vacante.sucursal_id)"
                            class="hover:text-foreground hover:underline"
                            >{{ vacante.sucursal }}</Link
                        >
                        <span v-else>{{ vacante.sucursal ?? 'Sin sucursal' }}</span>
                        <template v-if="vacante.departamento"><span aria-hidden="true">·</span>{{ vacante.departamento }}</template>
                    </p>
                </div>

                <div class="text-sm">
                    <p class="font-semibold tabular-nums">
                        {{ vacante.plazas_disponibles }} {{ vacante.plazas_disponibles === 1 ? 'plaza por cubrir' : 'plazas por cubrir' }}
                    </p>
                    <p v-if="vacante.plantilla_autorizada !== null" class="text-xs text-muted-foreground">
                        {{ vacante.plantilla_actual }} de {{ vacante.plantilla_autorizada }} autorizadas ocupadas
                    </p>
                    <p v-else class="text-xs text-muted-foreground">{{ vacante.motivo }}</p>
                </div>

                <div class="text-sm">
                    <p>Abierta hace {{ vacante.dias_abierta }} {{ vacante.dias_abierta === 1 ? 'día' : 'días' }}</p>
                    <p class="text-xs text-muted-foreground">Desde el {{ fecha(vacante.fecha_apertura) }}</p>
                </div>

                <div class="flex items-center gap-2 @3xl:justify-end">
                    <Button as-child variant="outline" size="sm">
                        <Link :href="indexCandidatos.url({ query: { vacante_id: vacante.id } })">
                            <UserSearch class="size-4" />
                            {{ vacante.candidatos_activos }} {{ vacante.candidatos_activos === 1 ? 'candidato' : 'candidatos' }}
                        </Link>
                    </Button>
                </div>
            </li>
        </ul>
    </div>
</template>
