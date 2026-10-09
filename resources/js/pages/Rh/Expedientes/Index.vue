<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronRight, DatabaseZap, FolderOpen, UserCog } from '@lucide/vue';
import { computed } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import ColaboradorCarpetaCard from '@/components/Rh/ColaboradorCarpetaCard.vue';
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
import { usePaginacion } from '@/composables/usePaginacion';
import { usePermisos } from '@/composables/usePermisos';
import { dashboard } from '@/routes';
import { exportarExcel, exportarPdf, index } from '@/routes/rh/expedientes';
import { index as indexMigracion } from '@/routes/rh/expedientes/migracion';
import type {
    ColaboradorExpedienteItem,
    EstadoUsuarioOpcion,
    OpcionSimple,
    RespuestaPaginada,
} from '@/types';

const { tienePermiso } = usePermisos();

const props = defineProps<{
    colaboradores: RespuestaPaginada<ColaboradorExpedienteItem>;
    filtros: {
        busqueda?: string;
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        estatus?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    empresasDisponibles: OpcionSimple[];
    sucursalesDisponibles: (OpcionSimple & { empresa_id: number | null })[];
    departamentosDisponibles: OpcionSimple[];
    puestosDisponibles: OpcionSimple[];
    estados: EstadoUsuarioOpcion[];
    datosIncompletosTotal: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Expedientes', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        estatus: props.filtros.estatus ?? 'activo',
        fecha_inicio: props.filtros.fecha_inicio ?? '',
        fecha_fin: props.filtros.fecha_fin ?? '',
    },
);

const filtrosActivos = computed(
    () =>
        (
            [
                'empresa_id',
                'sucursal_id',
                'departamento_id',
                'puesto_id',
                'fecha_inicio',
                'fecha_fin',
            ] as const
        ).filter((campo) => Boolean(filtros[campo])).length +
        (filtros.estatus && filtros.estatus !== 'activo' ? 1 : 0),
);
const { irA } = usePaginacion();
function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}

const sucursalesFiltradas = computed(() =>
    filtros.empresa_id
        ? props.sucursalesDisponibles.filter(
              (s) => String(s.empresa_id) === filtros.empresa_id,
          )
        : props.sucursalesDisponibles,
);

const empresaActiva = computed(() =>
    props.empresasDisponibles.find((e) => String(e.id) === filtros.empresa_id),
);
const sucursalActiva = computed(() =>
    props.sucursalesDisponibles.find(
        (s) => String(s.id) === filtros.sucursal_id,
    ),
);
</script>

<template>
    <Head title="Expedientes" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Expedientes"
            descripcion="Explora los expedientes digitales por empresa, sucursal y colaborador."
            :icono="FolderOpen"
        >
            <Button
                v-if="tienePermiso('expedientes.migrar')"
                variant="outline"
                as-child
            >
                <Link :href="indexMigracion()">
                    <DatabaseZap class="size-4" />
                    Migración inicial de expedientes
                </Link>
            </Button>
            <CrudExportButtons
                :url-excel="urlExportar(exportarExcel)"
                :url-pdf="urlExportar(exportarPdf)"
            />
        </CrudPageHeader>

        <div
            v-if="datosIncompletosTotal > 0"
            class="flex items-center gap-3 rounded-xl border border-warning/30 bg-warning-soft/50 px-4 py-3 text-sm text-warning"
        >
            <UserCog class="size-4 shrink-0" />
            <p>
                <span class="font-semibold">{{ datosIncompletosTotal }}</span>
                colaborador{{ datosIncompletosTotal === 1 ? '' : 'es' }}
                requiere{{ datosIncompletosTotal === 1 ? '' : 'n' }} completar
                información para poder generarle(s) documentos (contratos,
                constancias…). Búscalo en la lista: su tarjeta lo marca con
                «Faltan datos para generar documentos».
            </p>
        </div>

        <!-- Ruta de exploración: solo tiene sentido al entrar a una empresa;
             sin eso era un "Empresas" suelto ocupando una fila. -->
        <nav
            v-if="empresaActiva"
            data-tour="expedientes-ruta"
            class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground"
        >
            <span :class="{ 'font-medium text-foreground': !empresaActiva }"
                >Empresas</span
            >
            <template v-if="empresaActiva">
                <ChevronRight class="size-3.5" />
                <span
                    :class="{ 'font-medium text-foreground': !sucursalActiva }"
                    >{{ empresaActiva.nombre }}</span
                >
            </template>
            <template v-if="sucursalActiva">
                <ChevronRight class="size-3.5" />
                <span class="font-medium text-foreground">{{
                    sucursalActiva.nombre
                }}</span>
            </template>
        </nav>

        <div class="flex flex-col gap-3">
            <CrudToolbar
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre o número de empleado..."
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
                :contador-filtros-activos="filtrosActivos"
                @limpiar="limpiar"
            >
                <!-- Filtros poco frecuentes en el panel lateral (no 7 controles
                     siempre visibles). -->
                <template #filtros>
                    <div
                        data-tour="expedientes-filtros"
                        class="flex flex-col gap-3 [&_[data-slot=select-trigger]]:w-full"
                    >
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
                                    v-for="opcion in empresasDisponibles"
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
                                    aplicar();
                                }
                            "
                        >
                            <SelectTrigger class="w-44">
                                <SelectValue placeholder="Departamento" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="opcion in departamentosDisponibles"
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
                                    v-for="opcion in puestosDisponibles"
                                    :key="opcion.id"
                                    :value="String(opcion.id)"
                                    >{{ opcion.nombre }}</SelectItem
                                >
                            </SelectContent>
                        </Select>

                        <Select
                            :model-value="filtros.estatus"
                            @update:model-value="
                                (v) => {
                                    filtros.estatus = String(v ?? '');
                                    aplicar();
                                }
                            "
                        >
                            <SelectTrigger class="w-40">
                                <SelectValue placeholder="Estado" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="todos"
                                    >Todos los estados</SelectItem
                                >
                                <SelectItem
                                    v-for="opcion in estados"
                                    :key="opcion.value"
                                    :value="opcion.value"
                                    >{{ opcion.etiqueta }}</SelectItem
                                >
                            </SelectContent>
                        </Select>

                        <div class="grid gap-1.5">
                            <Label class="text-xs text-muted-foreground"
                                >Ingreso desde</Label
                            >
                            <DatePicker
                                class="h-9 w-full"
                                :model-value="filtros.fecha_inicio"
                                @update:model-value="
                                    (v) => {
                                        filtros.fecha_inicio = v;
                                        aplicar();
                                    }
                                "
                            />
                        </div>
                        <div class="grid gap-1.5">
                            <Label class="text-xs text-muted-foreground"
                                >Hasta</Label
                            >
                            <DatePicker
                                class="h-9 w-full"
                                :model-value="filtros.fecha_fin"
                                @update:model-value="
                                    (v) => {
                                        filtros.fecha_fin = String(v ?? '');
                                        aplicar();
                                    }
                                "
                            />
                        </div>
                    </div>
                </template>
            </CrudToolbar>
        </div>

        <CrudEmptyState
            v-if="!colaboradores.data.length"
            :icono="FolderOpen"
            titulo="No se encontraron colaboradores"
            descripcion="Ajusta los filtros o la búsqueda para encontrar un expediente."
        />

        <div
            v-else
            data-tour="expedientes-tarjetas"
            class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
        >
            <ColaboradorCarpetaCard
                v-for="colaborador in colaboradores.data"
                :key="colaborador.id"
                :colaborador="colaborador"
            />
        </div>

        <div
            v-if="colaboradores.last_page > 1"
            class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground"
        >
            <span
                >Mostrando {{ colaboradores.from ?? 0 }}–{{
                    colaboradores.to ?? 0
                }}
                de {{ colaboradores.total }}</span
            >
            <div class="flex flex-wrap gap-1">
                <button
                    v-for="(enlace, indice) in colaboradores.links"
                    :key="indice"
                    type="button"
                    :disabled="!enlace.url"
                    @click="irA(enlace.url)"
                    :class="[
                        'min-w-9 rounded-md border px-3 py-1.5 text-sm transition-colors',
                        enlace.active
                            ? 'border-transparent bg-primary text-primary-foreground'
                            : 'border-border hover:bg-accent',
                        !enlace.url
                            ? 'cursor-not-allowed opacity-50'
                            : 'cursor-pointer',
                    ]"
                    v-html="enlace.label"
                />
            </div>
        </div>
    </div>
</template>
