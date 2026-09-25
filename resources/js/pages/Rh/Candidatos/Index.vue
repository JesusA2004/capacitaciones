<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Banknote,
    CalendarClock,
    FileText,
    Percent,
    Plus,
    Target,
    Trophy,
    UserCheck2,
    Users,
    UserRound,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import CandidatoFormDialog from '@/components/Rh/CandidatoFormDialog.vue';
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
import { useAlertas } from '@/composables/useAlertas';
import { useCelebracion } from '@/composables/useCelebracion';
import { useFiltros } from '@/composables/useFiltros';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    estado as estadoUrl,
    exportarExcel,
    exportarPdf,
    index,
    show,
} from '@/routes/rh/candidatos';
import cv from '@/routes/rh/candidatos/cv';
import type { CandidatoItem, CandidatosKpis, OpcionesReclutamiento } from '@/types';

const props = defineProps<{
    candidatos: CandidatoItem[];
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_objetivo_id?: string;
        vacante_id?: string;
        responsable_rh_id?: string;
        fuente?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
        mes?: string;
    };
    opciones: OpcionesReclutamiento;
    kpis: CandidatosKpis;
}>();

const MESES_NOMBRE = [
    'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
    'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre',
];

const opcionesMes = computed(() => {
    const hoy = new Date();
    const opciones: { value: string; etiqueta: string }[] = [];

    for (let i = 0; i < 12; i++) {
        const fecha = new Date(hoy.getFullYear(), hoy.getMonth() - i, 1);
        const valor = `${fecha.getFullYear()}-${String(fecha.getMonth() + 1).padStart(2, '0')}`;
        opciones.push({
            value: valor,
            etiqueta: `${MESES_NOMBRE[fecha.getMonth()]} ${fecha.getFullYear()}`,
        });
    }

    return opciones;
});

const pipelineKpi = computed(() => [
    { etiqueta: 'Recibidos en el periodo', valor: props.kpis.recibidos_periodo, icono: Users },
    { etiqueta: 'En proceso', valor: props.kpis.en_proceso, icono: UserRound, tono: 'info' as const },
    { etiqueta: 'Finalistas', valor: props.kpis.finalistas, icono: Trophy, tono: 'warning' as const },
    { etiqueta: 'Contratados en el periodo', valor: props.kpis.contratados_periodo, icono: UserCheck2, tono: 'success' as const },
]);

const resultadosKpi = computed(() => [
    {
        etiqueta: 'Tasa de conversión',
        valor: `${Math.round(props.kpis.tasa_conversion * 1000) / 10}%`,
        icono: Percent,
    },
    {
        etiqueta: 'Tiempo promedio de contratación',
        valor: props.kpis.tiempo_promedio_contratacion_dias !== null
            ? `${props.kpis.tiempo_promedio_contratacion_dias} días`
            : '—',
        icono: CalendarClock,
    },
]);

const costosKpi = computed(() => {
    if (props.kpis.gasto_reclutamiento_periodo === undefined) {
        return [];
    }

    return [
        { etiqueta: 'Gasto de reclutamiento', valor: formatoMoneda(props.kpis.gasto_reclutamiento_periodo), icono: Banknote },
        { etiqueta: 'Costo por candidato', valor: formatoMoneda(props.kpis.costo_por_candidato ?? 0), icono: Target },
        { etiqueta: 'Costo por contratación', valor: formatoMoneda(props.kpis.costo_por_contratacion ?? 0), icono: Banknote, tono: 'warning' as const },
    ];
});

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
        fuente: props.filtros.fuente ?? '',
        busqueda: props.filtros.busqueda ?? '',
        fecha_inicio: props.filtros.fecha_inicio ?? '',
        fecha_fin: props.filtros.fecha_fin ?? '',
        mes: props.filtros.mes ?? opcionesMes.value[0].value,
    },
);
const filtroSheetAbierto = ref(false);

function fuenteEtiqueta(valor: string | null): string {
    if (!valor) {
        return '—';
    }

    return props.opciones.fuentes?.find((f) => f.value === valor)?.etiqueta ?? valor;
}

function diasEnFase(candidato: CandidatoItem): number {
    const fechaBase = candidato.ultimo_cambio_estado?.fecha ?? candidato.created_at;

    return Math.max(
        0,
        Math.floor((Date.now() - new Date(fechaBase).getTime()) / 86_400_000),
    );
}

function ultimaNota(candidato: CandidatoItem): string | null {
    return candidato.ultimo_seguimiento?.nota ?? null;
}
function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    const parametros = new URLSearchParams(
        Object.entries(filtros).filter(([, valor]) => valor),
    );

    return `${destino.url()}?${parametros.toString()}`;
}
const { mostrarError } = useAlertas();
const { celebrar } = useCelebracion();

const COLUMNAS = props.opciones.estados ?? [];
const transicionesPermitidas = props.opciones.transicionesPermitidas ?? {};

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

const arrastrando = ref<CandidatoItem | null>(null);

function columnaPermitida(valorColumna: string): boolean {
    if (!arrastrando.value) {
        return true;
    }

    if (arrastrando.value.estado === valorColumna) {
        return true;
    }

    return (transicionesPermitidas[arrastrando.value.estado] ?? []).includes(
        valorColumna,
    );
}

function alSoltar(nuevoEstado: string) {
    const candidato = arrastrando.value;
    arrastrando.value = null;

    if (!candidato || candidato.estado === nuevoEstado) {
        return;
    }

    if (
        !(transicionesPermitidas[candidato.estado] ?? []).includes(
            nuevoEstado,
        )
    ) {
        mostrarError('Las fases no pueden retroceder.');

        return;
    }

    router.put(
        estadoUrl.url(candidato.id),
        { estado: nuevoEstado },
        {
            preserveScroll: true,
            onSuccess: () => {
                if (nuevoEstado === 'contratado') {
                    celebrar();
                }
            },
            onError: () =>
                mostrarError('No tienes permiso para mover este candidato.'),
        },
    );
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
            <CrudExportButtons
                :url-excel="urlExportar(exportarExcel)"
                :url-pdf="urlExportar(exportarPdf)"
            />
            <Button data-tour="candidatos-nuevo" @click="abrirCrear">
                <Plus class="size-4" />
                Nuevo candidato
            </Button>
        </CrudPageHeader>

        <div data-tour="candidatos-kpis" class="flex flex-col gap-4">
            <CrudStats :estadisticas="pipelineKpi" />
            <CrudStats :estadisticas="[...resultadosKpi, ...costosKpi]" />
        </div>

        <div data-tour="candidatos-filtros" class="flex flex-wrap items-center gap-2">
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
                :model-value="filtros.mes"
                @update:model-value="
                    (v) => {
                        filtros.mes = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44"
                    ><SelectValue placeholder="Mes"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opcionesMes"
                        :key="opcion.value"
                        :value="opcion.value"
                        >{{ opcion.etiqueta }}</SelectItem
                    >
                </SelectContent>
            </Select>

            <Select
                :model-value="filtros.fuente"
                @update:model-value="
                    (v) => {
                        filtros.fuente = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-44"
                    ><SelectValue placeholder="Todas las fuentes"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="opcion in opciones.fuentes"
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
                        <DatePicker v-model="filtros.fecha_inicio" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Registrado hasta</Label>
                        <DatePicker v-model="filtros.fecha_fin" />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <div data-tour="candidatos-tablero" class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="columna in columnas"
                :key="columna.value"
                class="flex w-64 shrink-0 flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3 transition-opacity"
                :class="
                    !columnaPermitida(columna.value) &&
                    'pointer-events-none opacity-30'
                "
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
                    <div
                        v-for="candidato in columna.candidatos"
                        :key="candidato.id"
                        draggable="true"
                        class="flex cursor-pointer flex-col gap-2 rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition-colors hover:border-primary/40"
                        @click="router.visit(show.url(candidato.id))"
                        @dragstart="arrastrando = candidato"
                        @dragend="arrastrando = null"
                    >
                        <div>
                            <p class="text-sm font-semibold leading-tight">
                                {{
                                    candidato.puesto_objetivo?.nombre ??
                                    'Sin puesto objetivo'
                                }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{
                                    `${candidato.nombre} ${candidato.apellidos ?? ''}`.trim()
                                }}
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-if="candidato.sucursal"
                                variant="outline"
                                class="text-[10px] font-normal"
                                >{{ candidato.sucursal.nombre }}</Badge
                            >
                            <Badge
                                variant="outline"
                                class="text-[10px] font-normal"
                                >{{ fuenteEtiqueta(candidato.fuente) }}</Badge
                            >
                        </div>

                        <div class="flex items-center justify-between text-[11px] text-muted-foreground">
                            <span class="truncate">{{
                                candidato.responsable_rh
                                    ? `${candidato.responsable_rh.name} ${candidato.responsable_rh.apellidos ?? ''}`.trim()
                                    : 'Sin responsable'
                            }}</span>
                            <span class="shrink-0"
                                >{{ diasEnFase(candidato) }} d. en fase</span
                            >
                        </div>

                        <p
                            v-if="ultimaNota(candidato)"
                            class="line-clamp-1 text-[11px] italic text-muted-foreground"
                        >
                            "{{ ultimaNota(candidato) }}"
                        </p>

                        <a
                            v-if="candidato.tiene_cv"
                            :href="cv.descargar.url(candidato.id)"
                            target="_blank"
                            class="flex items-center gap-1 self-start rounded-md bg-muted px-2 py-1 text-[11px] font-medium text-foreground transition-colors hover:bg-muted/70"
                            @click.stop
                        >
                            <FileText class="size-3" />
                            Ver CV
                        </a>
                    </div>

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
