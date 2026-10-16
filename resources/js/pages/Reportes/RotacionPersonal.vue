<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    FileDown,
    FileSpreadsheet,
    FileUp,
    TrendingDown,
    XCircle,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { VueApexCharts } from '@/lib/apexDiferido';
import { formatearFecha } from '@/lib/fechas';
import { dashboard } from '@/routes';
import { index as indexReportes } from '@/routes/reportes';
import rutasRotacion from '@/routes/reportes/rotacion';
import type {
    NivelHallazgoRotacion,
    RotacionCargaDetalle,
    RotacionCargaResumen,
    RotacionTablero,
} from '@/types';

const props = defineProps<{
    tablero: RotacionTablero;
    filtros: { sucursal_id: number | null; hasta: string | null };
    sucursales: { id: number; nombre: string }[];
    puedeCargar: boolean;
    cargas: RotacionCargaResumen[];
    cargaSeleccionada: RotacionCargaDetalle | null;
    anioFormato: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reportes', href: indexReportes() },
            { title: 'Rotación de personal', href: '' },
        ],
    },
});

// reka-ui no admite value="" en <SelectItem>: centinela para «Todas».
const TODAS = '__todas__';
const sucursal = ref<string>(
    props.filtros.sucursal_id ? String(props.filtros.sucursal_id) : TODAS,
);
const hasta = ref<string>(props.filtros.hasta ?? props.tablero.ventana.hasta);

function aplicarFiltros() {
    router.get(
        rutasRotacion.index.url(),
        {
            ...(sucursal.value !== TODAS
                ? { sucursal_id: sucursal.value }
                : {}),
            ...(hasta.value ? { hasta: hasta.value } : {}),
            ...(props.cargaSeleccionada
                ? { carga: props.cargaSeleccionada.id }
                : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            only: ['tablero', 'filtros'],
        },
    );
}

watch([sucursal, hasta], aplicarFiltros);

const porcentaje = (v: number | null | undefined) =>
    v === null || v === undefined
        ? '—'
        : `${v.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} %`;

const mesesConDatos = computed(() =>
    props.tablero.meses.filter((m) => m.sucursales > 0),
);
const hayVoluntarias = computed(() =>
    props.tablero.meses.some(
        (m) => m.bajas_voluntarias !== null || m.bajas_involuntarias !== null,
    ),
);

const series = computed(() => [
    {
        name: 'Índice de rotación',
        type: 'line',
        data: props.tablero.meses.map((m) => m.indice),
    },
    {
        name: 'Bajas',
        type: 'column',
        data: props.tablero.meses.map((m) => (m.sucursales ? m.bajas : null)),
    },
]);

const opciones = computed(() => ({
    chart: {
        toolbar: { show: false },
        fontFamily: 'inherit',
        zoom: { enabled: false },
    },
    colors: [
        'var(--grafica-rotacion, var(--brand-primary))',
        'var(--pastel-oro)',
    ],
    stroke: { width: [3, 0], curve: 'smooth' as const },
    markers: { size: 4 },
    dataLabels: { enabled: false },
    plotOptions: { bar: { borderRadius: 4, columnWidth: '45%' } },
    legend: { position: 'bottom' as const },
    grid: { borderColor: 'var(--border)' },
    xaxis: { categories: props.tablero.meses.map((m) => m.etiqueta) },
    yaxis: [
        {
            title: { text: 'Índice (%)' },
            labels: { formatter: (v: number) => `${Math.round(v)} %` },
        },
        {
            opposite: true,
            title: { text: 'Bajas' },
            labels: { formatter: (v: number) => `${Math.round(v)}` },
        },
    ],
    tooltip: {
        y: [
            {
                formatter: (v: number) =>
                    v === null ? 'Sin datos' : porcentaje(v),
            },
            {
                formatter: (v: number) => (v === null ? 'Sin datos' : `${v}`),
            },
        ],
    },
}));

// --- Carga del Excel ---
const form = useForm<{ archivo: File[] }>({ archivo: [] });

function analizar() {
    if (form.archivo.length === 0) {
        return;
    }

    form.transform((d) => ({ archivo: d.archivo[0] })).post(
        rutasRotacion.analizar.url(),
        { forceFormData: true, onSuccess: () => form.reset() },
    );
}

const procesando = ref(false);

function confirmar(carga: RotacionCargaDetalle) {
    const reemplazos = carga.periodos.filter(
        (p) => p.accion === 'reemplaza',
    ).length;
    const texto =
        reemplazos > 0
            ? `Se cargarán los meses del archivo y se reemplazarán ${reemplazos} mes(es) ya cargado(s). ¿Continuar?`
            : '¿Cargar los meses del archivo?';

    if (!window.confirm(texto)) {
        return;
    }

    router.post(
        rutasRotacion.aplicar.url(carga.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (procesando.value = true),
            onFinish: () => (procesando.value = false),
        },
    );
}

function descartar(carga: RotacionCargaDetalle) {
    if (
        !window.confirm(
            `¿Descartar «${carga.nombre_archivo}»? No se cargará nada; el archivo queda guardado como registro.`,
        )
    ) {
        return;
    }

    router.post(
        rutasRotacion.descartar.url(carga.id),
        {},
        { preserveScroll: true },
    );
}

const filtroHallazgos = ref<NivelHallazgoRotacion | 'todos'>('todos');
const hallazgosVisibles = computed(() => {
    const lista = props.cargaSeleccionada?.hallazgos ?? [];

    return filtroHallazgos.value === 'todos'
        ? lista
        : lista.filter((h) => h.nivel === filtroHallazgos.value);
});

const NIVELES: {
    valor: NivelHallazgoRotacion;
    titulo: string;
    clase: string;
}[] = [
    { valor: 'error', titulo: 'No se importa', clase: 'bg-danger-soft' },
    { valor: 'advertencia', titulo: 'Revisar', clase: 'bg-warning-soft' },
    { valor: 'informativo', titulo: 'Informativo', clase: 'bg-info-soft' },
];

const claseNivel = (nivel: NivelHallazgoRotacion) =>
    NIVELES.find((n) => n.valor === nivel)?.clase ?? '';

const claseAccion = (accion: string) =>
    accion === 'nuevo'
        ? 'bg-success-soft'
        : accion === 'reemplaza'
          ? 'bg-warning-soft'
          : 'bg-crema';

const claseEstado = (estado: string) =>
    estado === 'aplicada'
        ? 'bg-success-soft'
        : estado === 'analizada'
          ? 'bg-warning-soft'
          : 'bg-crema';

const NOMBRE_MES = [
    'Ene',
    'Feb',
    'Mar',
    'Abr',
    'May',
    'Jun',
    'Jul',
    'Ago',
    'Sep',
    'Oct',
    'Nov',
    'Dic',
];
</script>

<template>
    <Head title="Rotación de personal" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Rotación de personal"
            descripcion="Índice de rotación mensual por sucursal, cargado desde el Excel de RH (bajas × 100 / plantilla del mes)."
            :icono="TrendingDown"
        >
            <a
                :href="
                    rutasRotacion.formato.url({ query: { anio: anioFormato } })
                "
            >
                <Button variant="outline">
                    <FileDown class="size-4" /> Descargar formato
                    {{ anioFormato }}
                </Button>
            </a>
        </CrudPageHeader>

        <!-- Filtros -->
        <section
            data-tour="rotacion-filtros"
            class="flex flex-wrap items-end gap-4 rounded-2xl border border-border bg-card p-4"
        >
            <div class="flex min-w-56 flex-col gap-1.5">
                <Label for="filtro-sucursal">Sucursal</Label>
                <Select v-model="sucursal">
                    <SelectTrigger id="filtro-sucursal" class="w-64">
                        <SelectValue placeholder="Todas" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem :value="TODAS">Todas</SelectItem>
                        <SelectItem
                            v-for="s in sucursales"
                            :key="s.id"
                            :value="String(s.id)"
                            >{{ s.nombre }}</SelectItem
                        >
                    </SelectContent>
                </Select>
            </div>
            <div class="flex flex-col gap-1.5">
                <Label for="filtro-hasta">Hasta el mes</Label>
                <MonthPicker
                    id="filtro-hasta"
                    v-model="hasta"
                    aria-label="Último mes de la ventana"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Últimos 12 meses: {{ tablero.ventana.desde }} a
                {{ tablero.ventana.hasta }}
            </p>
        </section>

        <!-- Indicadores -->
        <section
            data-tour="rotacion-indicadores"
            class="grid grid-cols-2 gap-3 lg:grid-cols-5"
        >
            <div class="rounded-2xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Altas</p>
                <p class="text-2xl font-semibold">
                    {{ tablero.totales.altas }}
                </p>
            </div>
            <div class="rounded-2xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Bajas</p>
                <p class="text-2xl font-semibold">
                    {{ tablero.totales.bajas }}
                </p>
            </div>
            <div class="rounded-2xl border border-border bg-card p-4">
                <p class="text-xs text-muted-foreground">Plantilla al cierre</p>
                <p class="text-2xl font-semibold">
                    {{ tablero.totales.plantilla_final ?? '—' }}
                </p>
            </div>
            <div class="rounded-2xl border border-border bg-salvia p-4">
                <p class="text-xs text-muted-foreground">Promedio mensual</p>
                <p class="text-2xl font-semibold">
                    {{ porcentaje(tablero.totales.promedio_mensual) }}
                </p>
            </div>
            <div class="rounded-2xl border border-border bg-crema p-4">
                <p class="text-xs text-muted-foreground">
                    Rotación acumulada ({{ tablero.totales.meses_con_datos }}
                    meses)
                </p>
                <p class="text-2xl font-semibold">
                    {{ porcentaje(tablero.totales.rotacion_acumulada) }}
                </p>
            </div>
        </section>

        <!-- Gráfica -->
        <section
            data-tour="rotacion-grafica"
            class="rounded-2xl border border-border bg-card p-4"
        >
            <h2 class="mb-2 text-sm font-semibold">
                Índice de rotación por mes
            </h2>
            <p
                v-if="mesesConDatos.length === 0"
                class="flex h-[280px] items-center justify-center text-center text-sm text-muted-foreground"
            >
                Todavía no hay rotación cargada para este periodo.
                <template v-if="puedeCargar">
                    Sube el Excel de RH más abajo.</template
                >
            </p>
            <VueApexCharts
                v-else
                type="line"
                height="300"
                :options="opciones"
                :series="series"
            />
        </section>

        <!-- Tabla mensual -->
        <section
            data-tour="rotacion-tabla"
            class="rounded-2xl border border-border bg-card p-4"
        >
            <h2 class="mb-3 text-sm font-semibold">Detalle mensual</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">Mes</th>
                            <th class="py-2 pr-3 text-right">Sucursales</th>
                            <th class="py-2 pr-3 text-right">
                                Plantilla inicial
                            </th>
                            <th class="py-2 pr-3 text-right">Altas</th>
                            <th class="py-2 pr-3 text-right">Bajas</th>
                            <template v-if="hayVoluntarias">
                                <th class="py-2 pr-3 text-right">
                                    Voluntarias
                                </th>
                                <th class="py-2 pr-3 text-right">
                                    Involuntarias
                                </th>
                            </template>
                            <th class="py-2 pr-3 text-right">
                                Plantilla final
                            </th>
                            <th class="py-2 text-right">Índice</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in tablero.meses"
                            :key="m.clave"
                            class="border-t border-border"
                        >
                            <td class="py-2 pr-3">{{ m.etiqueta }}</td>
                            <template v-if="m.sucursales">
                                <td class="py-2 pr-3 text-right">
                                    {{ m.sucursales }}
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ m.plantilla_inicial }}
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ m.altas }}
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ m.bajas }}
                                </td>
                                <template v-if="hayVoluntarias">
                                    <td class="py-2 pr-3 text-right">
                                        {{ m.bajas_voluntarias ?? '—' }}
                                    </td>
                                    <td class="py-2 pr-3 text-right">
                                        {{ m.bajas_involuntarias ?? '—' }}
                                    </td>
                                </template>
                                <td class="py-2 pr-3 text-right">
                                    {{ m.plantilla_final }}
                                </td>
                                <td class="py-2 text-right font-medium">
                                    {{ porcentaje(m.indice) }}
                                </td>
                            </template>
                            <td
                                v-else
                                :colspan="hayVoluntarias ? 8 : 6"
                                class="py-2 text-right text-xs text-muted-foreground"
                            >
                                Sin datos cargados
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Por sucursal -->
        <section
            v-if="tablero.por_sucursal.length > 1"
            class="rounded-2xl border border-border bg-card p-4"
        >
            <h2 class="mb-3 text-sm font-semibold">Por sucursal (ventana)</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-2 pr-3">Sucursal</th>
                            <th class="py-2 pr-3 text-right">Meses</th>
                            <th class="py-2 pr-3 text-right">Altas</th>
                            <th class="py-2 pr-3 text-right">Bajas</th>
                            <th class="py-2 pr-3 text-right">
                                Plantilla al cierre
                            </th>
                            <th class="py-2 pr-3 text-right">
                                Promedio mensual
                            </th>
                            <th class="py-2 text-right">Rotación acumulada</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="s in tablero.por_sucursal"
                            :key="s.sucursal_id"
                            class="border-t border-border"
                        >
                            <td class="py-2 pr-3">
                                <button
                                    type="button"
                                    class="text-left underline-offset-2 hover:underline"
                                    @click="sucursal = String(s.sucursal_id)"
                                >
                                    {{ s.sucursal }}
                                </button>
                            </td>
                            <td class="py-2 pr-3 text-right">{{ s.meses }}</td>
                            <td class="py-2 pr-3 text-right">{{ s.altas }}</td>
                            <td class="py-2 pr-3 text-right">{{ s.bajas }}</td>
                            <td class="py-2 pr-3 text-right">
                                {{ s.plantilla_final }}
                            </td>
                            <td class="py-2 pr-3 text-right">
                                {{ porcentaje(s.promedio_mensual) }}
                            </td>
                            <td class="py-2 text-right font-medium">
                                {{ porcentaje(s.rotacion_acumulada) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Carga del Excel (solo RH con rotacion.cargar) -->
        <template v-if="puedeCargar">
            <section
                data-tour="rotacion-carga"
                class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5"
            >
                <h2 class="text-sm font-semibold">Cargar Excel de rotación</h2>
                <p class="text-xs text-muted-foreground">
                    Sube el «INDICE DE ROTACIÓN DE PERSONAL» (o el formato
                    actualizado). Primero verás una vista previa con todo lo que
                    no cuadró; nada se carga hasta que confirmes. Volver a subir
                    un mes ya cargado lo reemplaza y queda registrado.
                </p>
                <form class="flex flex-col gap-3" @submit.prevent="analizar">
                    <PeopleFileDropzone
                        v-model="form.archivo"
                        accept=".xlsx,.xls"
                        label="Arrastra el archivo .xlsx aquí"
                        hint="Excel de rotación de personal (máx. 10 MB)"
                    />
                    <InputError :message="form.errors.archivo" />
                    <div>
                        <Button
                            type="submit"
                            :disabled="
                                form.archivo.length === 0 || form.processing
                            "
                        >
                            <FileUp class="size-4" /> Analizar archivo
                        </Button>
                    </div>
                </form>
            </section>

            <!-- Vista previa / resultado de una carga -->
            <section
                v-if="cargaSeleccionada"
                class="flex flex-col gap-4 rounded-2xl border border-border bg-card p-5"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div>
                        <h2 class="text-sm font-semibold">
                            {{ cargaSeleccionada.nombre_archivo }}
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            Subido por {{ cargaSeleccionada.subido_por }} el
                            {{ formatearFecha(cargaSeleccionada.creado_en) }}
                            <template v-if="cargaSeleccionada.aplicado_en">
                                · aplicado por
                                {{ cargaSeleccionada.aplicado_por }} el
                                {{
                                    formatearFecha(
                                        cargaSeleccionada.aplicado_en,
                                    )
                                }}
                            </template>
                        </p>
                        <span
                            class="mt-2 inline-block rounded-full px-2 py-0.5 text-xs"
                            :class="claseEstado(cargaSeleccionada.estado)"
                            >{{ cargaSeleccionada.estado_etiqueta }}</span
                        >
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a
                            :href="
                                rutasRotacion.archivo.url(cargaSeleccionada.id)
                            "
                        >
                            <Button variant="outline" size="sm">
                                <Download class="size-4" /> Archivo original
                            </Button>
                        </a>
                        <template
                            v-if="cargaSeleccionada.estado === 'analizada'"
                        >
                            <Button
                                variant="ghost"
                                size="sm"
                                class="text-destructive"
                                :disabled="procesando"
                                @click="descartar(cargaSeleccionada)"
                            >
                                <XCircle class="size-4" /> Descartar
                            </Button>
                            <Button
                                size="sm"
                                :disabled="
                                    !cargaSeleccionada.puede_aplicar ||
                                    procesando
                                "
                                @click="confirmar(cargaSeleccionada)"
                            >
                                <CheckCircle2 class="size-4" /> Confirmar carga
                            </Button>
                        </template>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <div class="rounded-xl bg-crema p-3">
                        <p class="text-xs text-muted-foreground">
                            Meses por sucursal
                        </p>
                        <p class="text-xl font-semibold">
                            {{ cargaSeleccionada.periodos_detectados }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-success-soft p-3">
                        <p class="text-xs text-muted-foreground">Nuevos</p>
                        <p class="text-xl font-semibold">
                            {{ cargaSeleccionada.periodos_nuevos }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-warning-soft p-3">
                        <p class="text-xs text-muted-foreground">
                            Reemplazan lo cargado
                        </p>
                        <p class="text-xl font-semibold">
                            {{ cargaSeleccionada.periodos_reemplazados }}
                        </p>
                    </div>
                    <div class="rounded-xl bg-salvia p-3">
                        <p class="text-xs text-muted-foreground">Sin cambios</p>
                        <p class="text-xl font-semibold">
                            {{ cargaSeleccionada.periodos_sin_cambios }}
                        </p>
                    </div>
                </div>

                <p
                    v-if="
                        cargaSeleccionada.resumen.sucursales_sin_catalogo
                            ?.length
                    "
                    class="rounded-xl bg-danger-soft p-3 text-sm"
                >
                    Sucursales del Excel que no están en el catálogo (no se
                    importan):
                    <strong>{{
                        cargaSeleccionada.resumen.sucursales_sin_catalogo.join(
                            ', ',
                        )
                    }}</strong>
                </p>

                <!-- Hallazgos -->
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="text-sm font-semibold">
                            Lo que no cuadró ({{
                                cargaSeleccionada.hallazgos.length
                            }})
                        </h3>
                        <button
                            type="button"
                            class="rounded-full border border-border px-2.5 py-0.5 text-xs"
                            :class="
                                filtroHallazgos === 'todos' ? 'bg-crema' : ''
                            "
                            @click="filtroHallazgos = 'todos'"
                        >
                            Todos
                        </button>
                        <button
                            v-for="n in NIVELES"
                            :key="n.valor"
                            type="button"
                            class="rounded-full border border-border px-2.5 py-0.5 text-xs"
                            :class="filtroHallazgos === n.valor ? n.clase : ''"
                            @click="filtroHallazgos = n.valor"
                        >
                            {{ n.titulo }} ({{
                                cargaSeleccionada.resumen.por_nivel?.[
                                    n.valor
                                ] ?? 0
                            }})
                        </button>
                    </div>
                    <div class="max-h-80 overflow-y-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th class="py-1.5 pr-3">Tipo</th>
                                    <th class="py-1.5 pr-3">Hoja</th>
                                    <th class="py-1.5 pr-3">Celda</th>
                                    <th class="py-1.5 pr-3">Sucursal</th>
                                    <th class="py-1.5">Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(h, i) in hallazgosVisibles"
                                    :key="i"
                                    class="border-t border-border align-top"
                                >
                                    <td class="py-1.5 pr-3">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs whitespace-nowrap"
                                            :class="claseNivel(h.nivel)"
                                            >{{ h.nivel_etiqueta }}</span
                                        >
                                    </td>
                                    <td class="py-1.5 pr-3 whitespace-nowrap">
                                        {{ h.hoja || '—' }}
                                    </td>
                                    <td class="py-1.5 pr-3">
                                        {{ h.celda ?? '—' }}
                                    </td>
                                    <td class="py-1.5 pr-3 whitespace-nowrap">
                                        {{ h.sucursal ?? '—' }}
                                    </td>
                                    <td class="py-1.5">{{ h.mensaje }}</td>
                                </tr>
                                <tr v-if="hallazgosVisibles.length === 0">
                                    <td
                                        colspan="5"
                                        class="py-4 text-center text-sm text-muted-foreground"
                                    >
                                        Sin hallazgos.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Meses que se cargan -->
                <div class="flex flex-col gap-2">
                    <h3 class="text-sm font-semibold">
                        Meses que se cargan ({{
                            cargaSeleccionada.periodos.length
                        }})
                    </h3>
                    <div class="max-h-96 overflow-auto">
                        <table class="w-full text-left text-sm">
                            <thead class="text-xs text-muted-foreground">
                                <tr>
                                    <th class="py-1.5 pr-3">Sucursal</th>
                                    <th class="py-1.5 pr-3">Mes</th>
                                    <th class="py-1.5 pr-3 text-right">
                                        Inicial
                                    </th>
                                    <th class="py-1.5 pr-3 text-right">
                                        Altas
                                    </th>
                                    <th class="py-1.5 pr-3 text-right">
                                        Bajas
                                    </th>
                                    <th class="py-1.5 pr-3 text-right">
                                        Final
                                    </th>
                                    <th class="py-1.5 pr-3 text-right">
                                        Índice
                                    </th>
                                    <th class="py-1.5">Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="p in cargaSeleccionada.periodos"
                                    :key="`${p.sucursal_id}-${p.anio}-${p.mes}`"
                                    class="border-t border-border"
                                >
                                    <td class="py-1.5 pr-3">
                                        {{ p.sucursal }}
                                        <span
                                            v-if="
                                                p.sucursal_excel.toLowerCase() !==
                                                p.sucursal.toLowerCase()
                                            "
                                            class="text-xs text-muted-foreground"
                                            >({{ p.sucursal_excel }})</span
                                        >
                                    </td>
                                    <td class="py-1.5 pr-3 whitespace-nowrap">
                                        {{ NOMBRE_MES[p.mes - 1] }} {{ p.anio }}
                                    </td>
                                    <td class="py-1.5 pr-3 text-right">
                                        {{ p.plantilla_inicial }}
                                    </td>
                                    <td class="py-1.5 pr-3 text-right">
                                        {{ p.altas }}
                                    </td>
                                    <td class="py-1.5 pr-3 text-right">
                                        {{ p.bajas }}
                                    </td>
                                    <td class="py-1.5 pr-3 text-right">
                                        {{ p.plantilla_final }}
                                    </td>
                                    <td class="py-1.5 pr-3 text-right">
                                        {{ porcentaje(p.indice) }}
                                    </td>
                                    <td class="py-1.5">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-xs whitespace-nowrap"
                                            :class="claseAccion(p.accion)"
                                            >{{ p.accion_etiqueta }}</span
                                        >
                                        <span
                                            v-if="
                                                p.accion === 'reemplaza' &&
                                                p.anterior
                                            "
                                            class="ml-1 text-xs text-muted-foreground"
                                        >
                                            antes: {{ p.anterior.altas }} altas,
                                            {{ p.anterior.bajas }} bajas,
                                            plantilla
                                            {{ p.anterior.plantilla_final }}
                                        </span>
                                    </td>
                                </tr>
                                <tr
                                    v-if="
                                        cargaSeleccionada.periodos.length === 0
                                    "
                                >
                                    <td
                                        colspan="8"
                                        class="py-4 text-center text-sm text-muted-foreground"
                                    >
                                        El archivo no tiene ningún mes que se
                                        pueda importar. Revisa los hallazgos.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>

            <!-- Historial de cargas -->
            <section
                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-5"
            >
                <h2 class="flex items-center gap-2 text-sm font-semibold">
                    <FileSpreadsheet class="size-4" /> Cargas anteriores
                </h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs text-muted-foreground">
                            <tr>
                                <th class="py-2 pr-3">Archivo</th>
                                <th class="py-2 pr-3">Estado</th>
                                <th class="py-2 pr-3 text-right">Meses</th>
                                <th class="py-2 pr-3 text-right">Nuevos</th>
                                <th class="py-2 pr-3 text-right">
                                    Reemplazados
                                </th>
                                <th class="py-2 pr-3 text-right">Hallazgos</th>
                                <th class="py-2 pr-3">Subido por</th>
                                <th class="py-2 pr-3">Fecha</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="c in cargas"
                                :key="c.id"
                                class="border-t border-border"
                            >
                                <td class="py-2 pr-3">
                                    {{ c.nombre_archivo }}
                                </td>
                                <td class="py-2 pr-3">
                                    <span
                                        class="rounded-full px-2 py-0.5 text-xs"
                                        :class="claseEstado(c.estado)"
                                        >{{ c.estado_etiqueta }}</span
                                    >
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ c.periodos_detectados }}
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ c.periodos_nuevos }}
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ c.periodos_reemplazados }}
                                </td>
                                <td class="py-2 pr-3 text-right">
                                    {{ c.hallazgos }}
                                </td>
                                <td class="py-2 pr-3">{{ c.subido_por }}</td>
                                <td class="py-2 pr-3">
                                    {{ formatearFecha(c.creado_en) }}
                                </td>
                                <td class="py-2 text-right">
                                    <Link
                                        :href="
                                            rutasRotacion.index.url({
                                                query: { carga: c.id },
                                            })
                                        "
                                        preserve-scroll
                                    >
                                        <Button variant="outline" size="sm"
                                            >Ver detalle</Button
                                        >
                                    </Link>
                                </td>
                            </tr>
                            <tr v-if="cargas.length === 0">
                                <td
                                    colspan="9"
                                    class="py-6 text-center text-sm text-muted-foreground"
                                >
                                    Todavía no se ha subido ningún archivo.
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </template>
    </div>
</template>
