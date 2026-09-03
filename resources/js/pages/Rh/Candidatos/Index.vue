<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { FileSpreadsheet, FileText, Plus, UserRound } from '@lucide/vue';
import { computed, ref } from 'vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
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
    estado as estadoUrl,
    exportarExcel,
    exportarPdf,
    index,
    show,
} from '@/routes/rh/candidatos';
import type { CandidatoItem, OpcionesReclutamiento } from '@/types';

const props = defineProps<{
    candidatos: CandidatoItem[];
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_objetivo_id?: string;
        vacante_id?: string;
        responsable_rh_id?: string;
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
            { title: 'Candidatos', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_objetivo_id: props.filtros.puesto_objetivo_id ?? '',
        vacante_id: props.filtros.vacante_id ?? '',
        responsable_rh_id: props.filtros.responsable_rh_id ?? '',
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
const { mostrarError } = useAlertas();

const COLUMNAS = props.opciones.estados ?? [];

const columnas = computed(() =>
    COLUMNAS.map((columna) => ({
        ...columna,
        candidatos: props.candidatos.filter((c) => c.estado === columna.value),
    })),
);

const dialogoAbierto = ref(false);
const seleccionado = ref<CandidatoItem | null>(null);

function abrirCrear() {
    seleccionado.value = null;
    dialogoAbierto.value = true;
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
                mostrarError('No tienes permiso para mover este candidato.'),
        },
    );
    arrastrando.value = null;
}
</script>

<template>
    <Head title="Candidatos" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Candidatos"
            descripcion="Seguimiento de prospectos y candidatos en proceso de reclutamiento."
            :icono="UserRound"
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
                Nuevo candidato
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por nombre o correo..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
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
                :model-value="filtros.puesto_objetivo_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_objetivo_id = String(v ?? '');
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
                descripcion="Departamento, vacante, responsable y fecha de registro."
                :contador-activos="
                    [
                        filtros.departamento_id,
                        filtros.vacante_id,
                        filtros.responsable_rh_id,
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

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Registrado desde</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Registrado hasta</Label>
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
                :key="columna.value"
                class="flex w-64 shrink-0 flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3"
                @dragover.prevent
                @drop="alSoltar(columna.value)"
            >
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-xs font-semibold">
                        {{ columna.etiqueta }}
                    </h3>
                    <Badge variant="outline">{{
                        columna.candidatos.length
                    }}</Badge>
                </div>

                <div class="flex flex-col gap-2">
                    <Link
                        v-for="candidato in columna.candidatos"
                        :key="candidato.id"
                        :href="show.url(candidato.id)"
                        draggable="true"
                        class="flex flex-col gap-1 rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition-colors hover:border-primary/40"
                        @dragstart="arrastrando = candidato.id"
                    >
                        <span class="text-sm font-medium">{{
                            `${candidato.nombre} ${candidato.apellidos ?? ''}`.trim()
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            candidato.puesto_objetivo?.nombre ??
                            'Sin puesto objetivo'
                        }}</span>
                        <span
                            v-if="candidato.tiene_cv"
                            class="text-xs text-muted-foreground"
                            >CV cargado</span
                        >
                    </Link>

                    <p
                        v-if="!columna.candidatos.length"
                        class="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground"
                    >
                        Sin candidatos
                    </p>
                </div>
            </div>
        </div>
    </div>

    <CandidatoFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :candidato="seleccionado"
        :opciones="opciones"
        :key="seleccionado?.id ?? 'nuevo'"
    />
</template>
