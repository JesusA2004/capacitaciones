<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Banknote,
    Megaphone,
    Plus,
    Target,
    UserCheck,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudActionMenu from '@/components/DataTable/CrudActionMenu.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudMobileCard from '@/components/DataTable/CrudMobileCard.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import DataTable from '@/components/DataTable/DataTable.vue';
import type { ColumnaDataTable } from '@/components/DataTable/DataTable.vue';
import CampanaReclutamientoFormDialog from '@/components/Rh/CampanaReclutamientoFormDialog.vue';
import { Button } from '@/components/ui/button';
import { DropdownMenuItem } from '@/components/ui/dropdown-menu';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import { destroy, index } from '@/routes/rh/campanas';
import type {
    CampanaReclutamientoItem,
    CampanasKpis,
    OpcionesCampanas,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    campanas: RespuestaPaginada<CampanaReclutamientoItem>;
    kpis: CampanasKpis;
    filtros: {
        mes: number;
        anio: number;
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        canal?: string;
    };
    opciones: OpcionesCampanas;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Campañas', href: index.url() },
        ],
    },
});

const meses = [
    { value: '1', etiqueta: 'Enero' },
    { value: '2', etiqueta: 'Febrero' },
    { value: '3', etiqueta: 'Marzo' },
    { value: '4', etiqueta: 'Abril' },
    { value: '5', etiqueta: 'Mayo' },
    { value: '6', etiqueta: 'Junio' },
    { value: '7', etiqueta: 'Julio' },
    { value: '8', etiqueta: 'Agosto' },
    { value: '9', etiqueta: 'Septiembre' },
    { value: '10', etiqueta: 'Octubre' },
    { value: '11', etiqueta: 'Noviembre' },
    { value: '12', etiqueta: 'Diciembre' },
];

const anioActual = new Date().getFullYear();
const anios = Array.from({ length: 5 }, (_, i) => String(anioActual - i));

const { filtros, aplicar, limpiar } = useFiltros(index.url(), {
    mes: String(props.filtros.mes),
    anio: String(props.filtros.anio),
    empresa_id: props.filtros.empresa_id ?? '',
    sucursal_id: props.filtros.sucursal_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    puesto_id: props.filtros.puesto_id ?? '',
    canal: props.filtros.canal ?? '',
});
const { confirmarEliminacion, mostrarExito, mostrarError } = useAlertas();

const sucursalesFiltradas = computed(() =>
    filtros.empresa_id
        ? props.opciones.sucursales.filter(
              (s) => String(s.empresa_id) === filtros.empresa_id,
          )
        : props.opciones.sucursales,
);

const puestosFiltrados = computed(() =>
    filtros.departamento_id
        ? props.opciones.puestos.filter(
              (p) => String(p.departamento_id) === filtros.departamento_id,
          )
        : props.opciones.puestos,
);

function nombreCanal(valor: string): string {
    return (
        props.opciones.canales.find((c) => c.value === valor)?.etiqueta ??
        valor
    );
}

const columnas: ColumnaDataTable[] = [
    { clave: 'canal', etiqueta: 'Canal' },
    { clave: 'periodo', etiqueta: 'Periodo' },
    { clave: 'ubicacion', etiqueta: 'Empresa / Sucursal / Puesto' },
    { clave: 'monto', etiqueta: 'Monto' },
    { clave: 'candidatos_generados', etiqueta: 'Candidatos' },
    { clave: 'observaciones', etiqueta: 'Observaciones' },
];

const dialogAbierto = ref(false);
const campanaSeleccionada = ref<CampanaReclutamientoItem | null>(null);

function abrirCrear() {
    campanaSeleccionada.value = null;
    dialogAbierto.value = true;
}

function abrirEditar(campana: CampanaReclutamientoItem) {
    campanaSeleccionada.value = campana;
    dialogAbierto.value = true;
}

async function eliminar(campana: CampanaReclutamientoItem) {
    const confirmado = await confirmarEliminacion(
        `la campaña de «${nombreCanal(campana.canal)}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(campana.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('La campaña se eliminó correctamente.'),
        onError: () => mostrarError('No fue posible eliminar la campaña.'),
    });
}
</script>

<template>
    <Head title="Campañas de reclutamiento" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Campañas de reclutamiento"
            descripcion="Gasto por canal (Meta, Indeed, Computrabajo, LinkedIn, referidos) y su costo por candidato/contratación."
            :icono="Megaphone"
        >
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva campaña
            </Button>
        </CrudPageHeader>

        <CrudStats
            :estadisticas="[
                {
                    etiqueta: 'Gasto del periodo',
                    valor: formatoMoneda(kpis.gasto_total),
                    icono: Banknote,
                },
                {
                    etiqueta: 'Candidatos generados',
                    valor: kpis.candidatos_generados,
                    icono: Users,
                    tono: 'info',
                },
                {
                    etiqueta: 'Costo por candidato',
                    valor: formatoMoneda(kpis.costo_por_candidato),
                    icono: Target,
                },
                {
                    etiqueta: 'Contratados',
                    valor: kpis.contratados,
                    icono: UserCheck,
                    tono: 'success',
                },
                {
                    etiqueta: 'Costo por contratación',
                    valor: formatoMoneda(kpis.costo_por_contratacion),
                    icono: Banknote,
                    tono: 'warning',
                },
            ]"
        />

        <div class="flex flex-wrap gap-2">
            <Select
                :model-value="filtros.mes"
                @update:model-value="
                    (v) => {
                        filtros.mes = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-36">
                    <SelectValue placeholder="Mes" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in meses"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.anio"
                @update:model-value="
                    (v) => {
                        filtros.anio = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-28">
                    <SelectValue placeholder="Año" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in anios"
                        :key="opcion"
                        :value="opcion"
                        >{{ opcion }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.canal"
                @update:model-value="
                    (v) => {
                        filtros.canal = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-40">
                    <SelectValue placeholder="Canal" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.canales"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.empresa_id"
                @update:model-value="
                    (v) => {
                        filtros.empresa_id = String(v ?? '');
                        filtros.sucursal_id = '';
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Empresa" />
                </SelectTrigger>
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
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Sucursal" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in sucursalesFiltradas"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.departamento_id"
                @update:model-value="
                    (v) => {
                        filtros.departamento_id = String(v ?? '');
                        filtros.puesto_id = '';
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Departamento" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.departamentos"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.puesto_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44">
                    <SelectValue placeholder="Puesto" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in puestosFiltrados"
                        :key="opcion.id"
                        :value="String(opcion.id)"
                        >{{ opcion.nombre }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <DataTable
            :columnas="columnas"
            :datos="campanas"
            mensaje-vacio="No se encontraron campañas en este periodo."
        >
            <template #vacio>
                <CrudEmptyState
                    :icono="Megaphone"
                    titulo="Todavía no hay campañas en este periodo"
                    descripcion="Registra el gasto de tus campañas de reclutamiento para ver el costo por candidato y por contratación."
                >
                    <Button size="sm" @click="abrirCrear">
                        <Plus class="size-4" />
                        Registrar campaña
                    </Button>
                </CrudEmptyState>
            </template>

            <template #celda-canal="{ fila }">
                <span class="font-medium">{{ nombreCanal(fila.canal) }}</span>
            </template>
            <template #celda-periodo="{ fila }">
                <span class="text-muted-foreground"
                    >{{
                        meses.find((m) => m.value === String(fila.mes))
                            ?.etiqueta
                    }}
                    {{ fila.anio }}</span
                >
            </template>
            <template #celda-ubicacion="{ fila }">
                <div class="text-sm text-muted-foreground">
                    <p>{{ fila.empresa?.nombre ?? 'Todas las empresas' }}</p>
                    <p class="text-xs">
                        {{ fila.sucursal?.nombre ?? 'Todas las sucursales' }}
                        ·
                        {{ fila.puesto?.nombre ?? 'General (sin puesto)' }}
                    </p>
                </div>
            </template>
            <template #celda-monto="{ fila }">
                <span class="font-medium">{{
                    formatoMoneda(fila.monto)
                }}</span>
            </template>
            <template #celda-candidatos_generados="{ fila }">
                <span class="text-muted-foreground">{{
                    fila.candidatos_generados ?? 'Auto'
                }}</span>
            </template>
            <template #celda-observaciones="{ fila }">
                <span class="line-clamp-2 text-muted-foreground">{{
                    fila.observaciones ?? '—'
                }}</span>
            </template>
            <template #acciones="{ fila }">
                <CrudActionMenu>
                    <DropdownMenuItem @select="abrirEditar(fila)"
                        >Editar</DropdownMenuItem
                    >
                    <DropdownMenuItem
                        variant="destructive"
                        @select="eliminar(fila)"
                        >Eliminar</DropdownMenuItem
                    >
                </CrudActionMenu>
            </template>

            <template #mobile-card="{ fila }">
                <CrudMobileCard
                    :titulo="nombreCanal(fila.canal)"
                    :subtitulo="`${fila.mes}/${fila.anio} · ${formatoMoneda(fila.monto)}`"
                >
                    <span>{{
                        fila.puesto?.nombre ?? 'General (sin puesto)'
                    }}</span>
                    <span
                        >{{
                            fila.candidatos_generados ?? 'Auto'
                        }}
                        candidatos</span
                    >
                    <template #acciones>
                        <CrudActionMenu>
                            <DropdownMenuItem @select="abrirEditar(fila)"
                                >Editar</DropdownMenuItem
                            >
                            <DropdownMenuItem
                                variant="destructive"
                                @select="eliminar(fila)"
                                >Eliminar</DropdownMenuItem
                            >
                        </CrudActionMenu>
                    </template>
                </CrudMobileCard>
            </template>
        </DataTable>
    </div>

    <CampanaReclutamientoFormDialog
        v-if="dialogAbierto"
        v-model:open="dialogAbierto"
        :campana="campanaSeleccionada"
        :opciones="opciones"
        :key="campanaSeleccionada?.id ?? 'nueva'"
    />
</template>
