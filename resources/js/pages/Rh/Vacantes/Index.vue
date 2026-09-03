<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Briefcase,
    FileSpreadsheet,
    FileText,
    Plus,
    Trash2,
    UserCheck,
    Users,
} from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CubrirVacanteDialog from '@/components/Rh/CubrirVacanteDialog.vue';
import VacanteFormDialog from '@/components/Rh/VacanteFormDialog.vue';
import { Badge } from '@/components/ui/badge';
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
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { dashboard } from '@/routes';
import {
    destroy,
    estado as estadoUrl,
    exportarExcel,
    exportarPdf,
    index,
} from '@/routes/rh/vacantes';
import type { OpcionesReclutamiento, VacanteItem } from '@/types';

const props = defineProps<{
    vacantes: VacanteItem[];
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        responsable_rh_id?: string;
        estado?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
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
        responsable_rh_id: props.filtros.responsable_rh_id ?? '',
        estado: props.filtros.estado ?? '',
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
const { confirmarEliminacion, mostrarError, mostrarExito } = useAlertas();

const COLUMNAS = [
    { estado: 'abierta', titulo: 'Abierta' },
    { estado: 'en_reclutamiento', titulo: 'En reclutamiento' },
    { estado: 'con_candidatos', titulo: 'Con candidatos' },
    { estado: 'en_revision', titulo: 'En revisión' },
    { estado: 'cubierta', titulo: 'Cubierta' },
    { estado: 'cancelada', titulo: 'Cancelada' },
];

const columnas = computed(() =>
    COLUMNAS.map((columna) => ({
        ...columna,
        vacantes: props.vacantes.filter((v) => v.estado === columna.estado),
    })),
);

const dialogoAbierto = ref(false);
const seleccionada = ref<VacanteItem | null>(null);
const prefillCreacion = ref<{
    puesto_id?: number;
    departamento_id?: number;
    empresa_id?: number;
    sucursal_id?: number;
    motivo?: string;
} | null>(null);

function abrirCrear() {
    seleccionada.value = null;
    dialogoAbierto.value = true;
}

// Llegada desde "Crear vacante para este puesto" en Jerarquía de puestos:
// ?crear=1&puesto_id=..&departamento_id=.. abre el diálogo precargado.
onMounted(() => {
    const query = new URLSearchParams(window.location.search);

    if (query.get('crear') !== '1') {
        return;
    }

    prefillCreacion.value = {
        puesto_id: props.filtros.puesto_id
            ? Number(props.filtros.puesto_id)
            : undefined,
        departamento_id: props.filtros.departamento_id
            ? Number(props.filtros.departamento_id)
            : undefined,
        empresa_id: props.filtros.empresa_id
            ? Number(props.filtros.empresa_id)
            : undefined,
        motivo: 'nueva_posicion',
    };
    abrirCrear();
});

function abrirEditar(vacante: VacanteItem) {
    seleccionada.value = vacante;
    dialogoAbierto.value = true;
}

const dialogoCubrirAbierto = ref(false);
const vacanteACubrir = ref<VacanteItem | null>(null);

function abrirCubrir(vacante: VacanteItem) {
    vacanteACubrir.value = vacante;
    dialogoCubrirAbierto.value = true;
}

async function eliminar(vacante: VacanteItem) {
    const confirmado = await confirmarEliminacion(
        `la vacante de «${vacante.puesto?.nombre ?? 'este puesto'}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(vacante.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Vacante eliminada.'),
        onError: () => mostrarError('No fue posible eliminar la vacante.'),
    });
}

const arrastrando = ref<number | null>(null);

function alSoltar(nuevoEstado: string) {
    if (arrastrando.value === null) {
        return;
    }

    router.put(
        estadoUrl.url(arrastrando.value),
        { estado: nuevoEstado },
        {
            preserveScroll: true,
            onError: () =>
                mostrarError('No tienes permiso para mover esta vacante.'),
        },
    );
    arrastrando.value = null;
}
</script>

<template>
    <Head title="Vacantes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Vacantes"
            descripcion="Da seguimiento a las vacantes abiertas y su cobertura."
            :icono="Briefcase"
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
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva vacante
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por puesto o departamento..."
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
            />

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

            <Select
                :model-value="filtros.puesto_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_id = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-48"
                    ><SelectValue placeholder="Todos los puestos"
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

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Departamento, responsable y rango de fechas de apertura."
                :contador-activos="
                    [
                        filtros.departamento_id,
                        filtros.responsable_rh_id,
                        filtros.estado,
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
                    <Label>Responsable RH</Label>
                    <Select
                        :model-value="filtros.responsable_rh_id"
                        @update:model-value="
                            (v) => (filtros.responsable_rh_id = String(v ?? ''))
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

                <div class="grid gap-2">
                    <Label>Estado</Label>
                    <Select
                        :model-value="filtros.estado"
                        @update:model-value="
                            (v) => (filtros.estado = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.estados"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Apertura desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Apertura hasta</Label>
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

        <div class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="columna in columnas"
                :key="columna.estado"
                class="flex w-72 shrink-0 flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3"
                @dragover.prevent
                @drop="alSoltar(columna.estado)"
            >
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-sm font-semibold">{{ columna.titulo }}</h3>
                    <Badge variant="outline">{{
                        columna.vacantes.length
                    }}</Badge>
                </div>

                <div class="flex flex-col gap-2">
                    <div
                        v-for="vacante in columna.vacantes"
                        :key="vacante.id"
                        draggable="true"
                        role="button"
                        tabindex="0"
                        class="group flex cursor-pointer flex-col gap-1 rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition-colors hover:border-primary/40"
                        @dragstart="arrastrando = vacante.id"
                        @click="abrirEditar(vacante)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-sm font-medium">{{
                                vacante.puesto?.nombre ?? 'Sin puesto'
                            }}</span>
                            <div
                                class="flex shrink-0 items-center gap-1 opacity-100 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                            >
                                <button
                                    v-if="
                                        columna.estado !== 'cubierta' &&
                                        columna.estado !== 'cancelada'
                                    "
                                    type="button"
                                    class="text-muted-foreground hover:text-primary"
                                    title="Cubrir vacante"
                                    @click.stop="abrirCubrir(vacante)"
                                >
                                    <UserCheck class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-destructive"
                                    title="Eliminar"
                                    @click.stop="eliminar(vacante)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </div>
                        </div>
                        <span class="text-xs text-muted-foreground">{{
                            vacante.sucursal?.nombre ?? 'Sin sucursal'
                        }}</span>
                        <div
                            class="mt-1 flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1">
                                <Users class="size-3" />
                                {{ vacante.candidatos_count }}
                            </span>
                            <span>{{ vacante.fecha_apertura }}</span>
                        </div>
                    </div>

                    <p
                        v-if="!columna.vacantes.length"
                        class="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground"
                    >
                        Sin vacantes
                    </p>
                </div>
            </div>
        </div>
    </div>

    <VacanteFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :vacante="seleccionada"
        :opciones="opciones"
        :prefill="seleccionada ? null : prefillCreacion"
        :key="seleccionada?.id ?? 'nueva'"
    />

    <CubrirVacanteDialog
        v-if="dialogoCubrirAbierto && vacanteACubrir"
        v-model:open="dialogoCubrirAbierto"
        :vacante="vacanteACubrir"
        :opciones="opciones"
        :key="`cubrir-${vacanteACubrir.id}`"
    />
</template>
