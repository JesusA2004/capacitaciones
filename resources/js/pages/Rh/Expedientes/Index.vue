<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { ChevronRight, FolderOpen } from '@lucide/vue';
import { computed } from 'vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import ColaboradorCarpetaCard from '@/components/Rh/ColaboradorCarpetaCard.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFiltros } from '@/composables/useFiltros';
import { usePaginacion } from '@/composables/usePaginacion';
import { dashboard } from '@/routes';
import { index } from '@/routes/rh/expedientes';
import type {
    ColaboradorExpedienteItem,
    EstadoUsuarioOpcion,
    OpcionSimple,
    RespuestaPaginada,
} from '@/types';

const props = defineProps<{
    colaboradores: RespuestaPaginada<ColaboradorExpedienteItem>;
    filtros: {
        busqueda?: string;
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        estatus?: string;
    };
    empresasDisponibles: OpcionSimple[];
    sucursalesDisponibles: (OpcionSimple & { empresa_id: number | null })[];
    departamentosDisponibles: OpcionSimple[];
    puestosDisponibles: OpcionSimple[];
    estados: EstadoUsuarioOpcion[];
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
        estatus: props.filtros.estatus ?? '',
    },
);
const { irA } = usePaginacion();

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

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Expedientes"
            descripcion="Explora los expedientes digitales por empresa, sucursal y colaborador."
            :icono="FolderOpen"
        />

        <nav
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
                @limpiar="limpiar"
            />

            <div class="flex flex-wrap gap-2">
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
                        <SelectItem
                            v-for="opcion in estados"
                            :key="opcion.value"
                            :value="opcion.value"
                            >{{ opcion.etiqueta }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
        </div>

        <CrudEmptyState
            v-if="!colaboradores.data.length"
            :icono="FolderOpen"
            titulo="No se encontraron colaboradores"
            descripcion="Ajusta los filtros o la búsqueda para encontrar un expediente."
        />

        <div
            v-else
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
