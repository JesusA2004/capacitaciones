<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    ChartColumn,
    FileDown,
    FileSpreadsheet,
    Info,
    RotateCcw,
} from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Combobox, ComboboxMultiple } from '@/components/ui/combobox';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatoMoneda } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    index,
    reporte as rutaReporte,
    reporteExcel,
    reportePdf,
} from '@/routes/rh/campanas';
import type {
    FiltrosReporteCampanas,
    OpcionesReporteCampanas,
    ReporteCampanasDatos,
} from '@/types';

const props = defineProps<{
    reporte: ReporteCampanasDatos;
    filtros: FiltrosReporteCampanas;
    opciones: OpcionesReporteCampanas;
    conCandidatos: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Campañas', href: index.url() },
            { title: 'Reporte', href: rutaReporte.url() },
        ],
    },
});

const page = usePage();
const errores = computed(
    () => (page.props.errors ?? {}) as Record<string, string>,
);

const filtros = reactive<FiltrosReporteCampanas>({
    ...props.filtros,
    campanas: [...props.filtros.campanas],
    canales: [...props.filtros.canales],
    estados: [...props.filtros.estados],
    fases: [...props.filtros.fases],
});

const errorRango = computed(() =>
    filtros.desde && filtros.hasta && filtros.hasta < filtros.desde
        ? 'La fecha de fin no puede ser anterior a la fecha de inicio.'
        : null,
);

/** Solo los filtros con valor (la URL queda corta y compartible). */
function consulta(origen: FiltrosReporteCampanas) {
    return Object.fromEntries(
        Object.entries(origen).filter(([, valor]) =>
            Array.isArray(valor) ? valor.length > 0 : valor !== '',
        ),
    ) as Record<string, string | string[]>;
}

function aplicar() {
    if (errorRango.value) {
        return;
    }

    router.get(rutaReporte.url(), consulta(filtros), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function limpiar() {
    router.get(rutaReporte.url(), {}, { preserveScroll: true });
}

// Descargas con los filtros con que se cargó la pantalla (lo que se ve).
const urlPdf = computed(() =>
    reportePdf.url({ query: consulta(props.filtros) }),
);
const urlExcel = computed(() =>
    reporteExcel.url({ query: consulta(props.filtros) }),
);

/* ---------- Rangos rápidos ---------- */
function iso(fecha: Date): string {
    const m = String(fecha.getMonth() + 1).padStart(2, '0');
    const d = String(fecha.getDate()).padStart(2, '0');

    return `${fecha.getFullYear()}-${m}-${d}`;
}

const hoy = new Date();
const presets = [
    {
        clave: 'este_mes',
        etiqueta: 'Este mes',
        desde: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 1)),
        hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)),
    },
    {
        clave: 'mes_pasado',
        etiqueta: 'Mes pasado',
        desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 1, 1)),
        hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth(), 0)),
    },
    {
        clave: 'ultimos_3',
        etiqueta: 'Últimos 3 meses',
        desde: iso(new Date(hoy.getFullYear(), hoy.getMonth() - 2, 1)),
        hasta: iso(new Date(hoy.getFullYear(), hoy.getMonth() + 1, 0)),
    },
    {
        clave: 'este_anio',
        etiqueta: 'Este año',
        desde: iso(new Date(hoy.getFullYear(), 0, 1)),
        hasta: iso(new Date(hoy.getFullYear(), 11, 31)),
    },
];

function usarPreset(preset: (typeof presets)[number]) {
    filtros.desde = preset.desde;
    filtros.hasta = preset.hasta;
    aplicar();
}

function cambiarDesde(valor: string) {
    filtros.desde = valor;

    // Si el inicio rebasa el fin, el fin se recorre al mismo día.
    if (filtros.hasta && valor && filtros.hasta < valor) {
        filtros.hasta = valor;
    }

    aplicar();
}

function cambiarHasta(valor: string) {
    filtros.hasta = valor;
    aplicar();
}

/* ---------- Opciones de los selectores ---------- */
const opcionesCampanas = computed(() =>
    props.opciones.campanas.map((c) => ({
        value: String(c.id),
        label: c.etiqueta,
    })),
);
const opcionesCanales = computed(() =>
    props.opciones.canales.map((c) => ({ value: c.value, label: c.etiqueta })),
);
const opcionesEstados = computed(() =>
    props.opciones.estados.map((c) => ({ value: c.value, label: c.etiqueta })),
);
const opcionesFases = computed(() =>
    props.opciones.fases.map((c) => ({ value: c.value, label: c.etiqueta })),
);
const opcionesEmpresas = computed(() =>
    props.opciones.empresas.map((e) => ({
        value: String(e.id),
        label: e.nombre,
    })),
);
const opcionesSucursales = computed(() =>
    props.opciones.sucursales
        .filter(
            (s) =>
                !filtros.empresa_id ||
                String(s.empresa_id) === filtros.empresa_id,
        )
        .map((s) => ({ value: String(s.id), label: s.nombre })),
);
const opcionesDepartamentos = computed(() =>
    props.opciones.departamentos.map((d) => ({
        value: String(d.id),
        label: d.nombre,
    })),
);
const opcionesPuestos = computed(() =>
    props.opciones.puestos
        .filter(
            (p) =>
                !filtros.departamento_id ||
                String(p.departamento_id) === filtros.departamento_id,
        )
        .map((p) => ({ value: String(p.id), label: p.nombre })),
);
const opcionesVacantes = computed(() =>
    props.opciones.vacantes.map((v) => ({
        value: String(v.id),
        label: v.etiqueta,
    })),
);
const opcionesResponsables = computed(() =>
    props.opciones.responsables.map((r) => ({
        value: String(r.id),
        label: r.nombre,
    })),
);

const criterioActual = computed(() =>
    props.opciones.criterios.find((c) => c.value === filtros.criterio_fecha),
);

function cambiar<K extends keyof FiltrosReporteCampanas>(
    clave: K,
    valor: FiltrosReporteCampanas[K],
) {
    filtros[clave] = valor;

    if (clave === 'empresa_id') {
        filtros.sucursal_id = '';
    }

    if (clave === 'departamento_id') {
        filtros.puesto_id = '';
    }

    aplicar();
}

/* ---------- Presentación ---------- */
const t = computed(() => props.reporte.totales);
const dinero = (v: number | null) => (v === null ? '—' : formatoMoneda(v));
const pct = (v: number | null) => (v === null ? '—' : `${v.toFixed(1)}%`);
const maxEmbudo = computed(() =>
    Math.max(1, ...t.value.embudo.map((f) => f.total)),
);
const maxDetenidos = computed(() =>
    Math.max(1, ...t.value.detenidos_por_fase.map((f) => f.total)),
);

const abierta = ref<number | null>(null);
</script>

<template>
    <Head title="Reporte de campañas" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Reporte de campañas de reclutamiento"
            descripcion="Filtra por rango de fechas, campaña o reporte general, y descarga el PDF o Excel con los mismos filtros."
            :icono="ChartColumn"
        >
            <Button variant="secondary" as-child>
                <a :href="urlExcel">
                    <FileSpreadsheet class="size-4" />
                    Descargar Excel
                </a>
            </Button>
            <Button as-child>
                <a :href="urlPdf" target="_blank" rel="noopener">
                    <FileDown class="size-4" />
                    Descargar PDF
                </a>
            </Button>
        </CrudPageHeader>

        <!-- ===================== Filtros ===================== -->
        <section
            data-tour="reporte-campanas-filtros"
            class="flex flex-col gap-4 rounded-2xl border bg-card p-4 shadow-sm"
        >
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid w-full gap-1.5 sm:w-52">
                    <Label for="reporte-desde">Fecha inicio</Label>
                    <DatePicker
                        id="reporte-desde"
                        :model-value="filtros.desde"
                        placeholder="Desde"
                        @update:model-value="cambiarDesde"
                    />
                </div>
                <div class="grid w-full gap-1.5 sm:w-52">
                    <Label for="reporte-hasta">Fecha fin</Label>
                    <DatePicker
                        id="reporte-hasta"
                        :model-value="filtros.hasta"
                        :min-value="filtros.desde || undefined"
                        placeholder="Hasta"
                        @update:model-value="cambiarHasta"
                    />
                </div>
                <div class="grid w-full gap-1.5 sm:w-64">
                    <Label>Fecha que se filtra</Label>
                    <Select
                        :model-value="filtros.criterio_fecha"
                        @update:model-value="
                            (v) =>
                                cambiar(
                                    'criterio_fecha',
                                    String(v ?? 'vigencia'),
                                )
                        "
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Vigencia de la campaña" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="c in opciones.criterios"
                                :key="c.value"
                                :value="c.value"
                                >{{ c.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="preset in presets"
                        :key="preset.clave"
                        size="sm"
                        :variant="
                            filtros.desde === preset.desde &&
                            filtros.hasta === preset.hasta
                                ? 'default'
                                : 'outline'
                        "
                        @click="usarPreset(preset)"
                    >
                        {{ preset.etiqueta }}
                    </Button>
                </div>
            </div>

            <InputError
                :message="errorRango ?? errores.hasta ?? errores.desde"
            />

            <p
                v-if="criterioActual"
                class="flex items-start gap-2 rounded-xl bg-menta px-3 py-2 text-xs text-muted-foreground"
            >
                <Info class="mt-0.5 size-3.5 shrink-0 text-esmeralda" />
                <span>{{ criterioActual.explicacion }}</span>
            </p>

            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
            >
                <div class="grid gap-1.5">
                    <Label>Campaña</Label>
                    <ComboboxMultiple
                        :items="opcionesCampanas"
                        :model-value="filtros.campanas"
                        texto-todos="Todas — reporte general"
                        placeholder="Buscar campaña..."
                        @update:model-value="(v) => cambiar('campanas', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Canal / medio</Label>
                    <ComboboxMultiple
                        :items="opcionesCanales"
                        :model-value="filtros.canales"
                        texto-todos="Todos los canales"
                        placeholder="Buscar canal..."
                        @update:model-value="(v) => cambiar('canales', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Estado de la campaña</Label>
                    <ComboboxMultiple
                        :items="opcionesEstados"
                        :model-value="filtros.estados"
                        texto-todos="Todos los estados"
                        placeholder="Buscar estado..."
                        @update:model-value="(v) => cambiar('estados', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Fase del candidato</Label>
                    <ComboboxMultiple
                        :items="opcionesFases"
                        :model-value="filtros.fases"
                        texto-todos="Todas las fases"
                        placeholder="Buscar fase..."
                        @update:model-value="(v) => cambiar('fases', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Empresa</Label>
                    <Combobox
                        :items="opcionesEmpresas"
                        :model-value="filtros.empresa_id"
                        placeholder="Todas las empresas"
                        @update:model-value="(v) => cambiar('empresa_id', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Sucursal</Label>
                    <Combobox
                        :items="opcionesSucursales"
                        :model-value="filtros.sucursal_id"
                        placeholder="Todas las sucursales"
                        @update:model-value="(v) => cambiar('sucursal_id', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Departamento</Label>
                    <Combobox
                        :items="opcionesDepartamentos"
                        :model-value="filtros.departamento_id"
                        placeholder="Todos los departamentos"
                        @update:model-value="
                            (v) => cambiar('departamento_id', v)
                        "
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Puesto</Label>
                    <Combobox
                        :items="opcionesPuestos"
                        :model-value="filtros.puesto_id"
                        placeholder="Todos los puestos"
                        @update:model-value="(v) => cambiar('puesto_id', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Vacante</Label>
                    <Combobox
                        :items="opcionesVacantes"
                        :model-value="filtros.vacante_id"
                        placeholder="Todas las vacantes"
                        @update:model-value="(v) => cambiar('vacante_id', v)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Responsable de RH</Label>
                    <Combobox
                        :items="opcionesResponsables"
                        :model-value="filtros.responsable_id"
                        placeholder="Todos los responsables"
                        @update:model-value="
                            (v) => cambiar('responsable_id', v)
                        "
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs text-muted-foreground">
                    Los filtros quedan en la dirección de la página: puedes
                    compartir el enlace y el PDF/Excel sale con lo mismo que ves
                    aquí.
                </p>
                <Button variant="ghost" size="sm" @click="limpiar">
                    <RotateCcw class="size-4" />
                    Limpiar filtros
                </Button>
            </div>
        </section>

        <!-- Filtros aplicados (los mismos del encabezado del PDF/Excel). -->
        <ul class="flex flex-wrap gap-2 text-xs">
            <li
                v-for="filtro in reporte.filtros_aplicados"
                :key="filtro.etiqueta"
                class="rounded-full border bg-crema px-3 py-1"
            >
                <span class="font-semibold">{{ filtro.etiqueta }}:</span>
                {{ filtro.valor }}
            </li>
        </ul>

        <CrudEmptyState
            v-if="reporte.campanas.length === 0"
            :icono="ChartColumn"
            titulo="No hay campañas con estos filtros"
            descripcion="Amplía el rango de fechas, cambia la fecha que se filtra o quita algún filtro."
        />

        <template v-else>
            <!-- ===================== Totales ===================== -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <MetricCard etiqueta="Campañas" :valor="t.campanas" />
                <MetricCard etiqueta="Gasto total" :valor="dinero(t.gasto)" />
                <MetricCard
                    etiqueta="Candidatos que llegaron"
                    :valor="t.llegaron"
                />
                <MetricCard
                    etiqueta="Contratados"
                    :valor="`${t.contratados} · ${pct(t.conversion)}`"
                />
                <MetricCard
                    etiqueta="Costo por candidato"
                    :valor="dinero(t.costo_por_candidato)"
                />
                <MetricCard
                    etiqueta="Costo por contratado"
                    :valor="dinero(t.costo_por_contratado)"
                />
                <MetricCard
                    etiqueta="Siguen en proceso"
                    :valor="t.en_proceso"
                />
                <MetricCard
                    etiqueta="No continuaron"
                    :valor="`${t.no_continuaron} · ${dinero(t.gasto_en_no_continuaron)}`"
                />
            </div>

            <div class="grid gap-4 lg:grid-cols-3">
                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold">
                        Embudo: ¿hasta dónde llegaron?
                    </h2>
                    <ul class="grid gap-2">
                        <li
                            v-for="fila in t.embudo"
                            :key="fila.etapa"
                            class="grid grid-cols-[minmax(0,10rem)_minmax(0,1fr)_4rem] items-center gap-3 text-sm"
                        >
                            <span class="truncate text-muted-foreground">{{
                                fila.etapa
                            }}</span>
                            <span
                                class="h-2.5 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-esmeralda/70"
                                    :style="{
                                        width: `${(fila.total / maxEmbudo) * 100}%`,
                                    }"
                                />
                            </span>
                            <span
                                class="text-right font-semibold tabular-nums"
                                >{{ fila.total }}</span
                            >
                        </li>
                    </ul>
                </section>

                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold">
                        ¿En qué fase se detuvieron?
                    </h2>
                    <p
                        v-if="t.detenidos_por_fase.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Nadie ha salido del proceso.
                    </p>
                    <ul v-else class="grid gap-2">
                        <li
                            v-for="fila in t.detenidos_por_fase"
                            :key="fila.fase"
                            class="grid grid-cols-[minmax(0,8rem)_minmax(0,1fr)_2.5rem] items-center gap-3 text-sm"
                        >
                            <span class="truncate text-muted-foreground">{{
                                fila.fase
                            }}</span>
                            <span
                                class="h-2.5 overflow-hidden rounded-full bg-muted"
                            >
                                <span
                                    class="block h-full rounded-full bg-danger-soft"
                                    :style="{
                                        width: `${(fila.total / maxDetenidos) * 100}%`,
                                    }"
                                />
                            </span>
                            <span
                                class="text-right font-semibold tabular-nums"
                                >{{ fila.total }}</span
                            >
                        </li>
                    </ul>
                </section>

                <section class="rounded-2xl border bg-card p-4 shadow-sm">
                    <h2 class="mb-3 text-sm font-semibold">
                        ¿Por qué no continuaron?
                    </h2>
                    <p
                        v-if="t.motivos.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        Sin salidas registradas.
                    </p>
                    <div
                        v-for="grupo in t.motivos"
                        :key="grupo.categoria"
                        class="mb-2 text-sm"
                    >
                        <p class="flex justify-between font-semibold">
                            <span>{{ grupo.categoria }}</span>
                            <span class="tabular-nums">{{ grupo.total }}</span>
                        </p>
                        <p
                            v-for="m in grupo.motivos"
                            :key="m.motivo"
                            class="flex justify-between pl-3 text-muted-foreground"
                        >
                            <span>{{ m.motivo }}</span>
                            <span class="tabular-nums">{{ m.total }}</span>
                        </p>
                    </div>
                </section>
            </div>

            <!-- ===================== Comparativo ===================== -->
            <section
                class="overflow-x-auto rounded-2xl border bg-card shadow-sm"
            >
                <table class="w-full min-w-[56rem] text-sm">
                    <thead class="bg-salvia text-left text-xs uppercase">
                        <tr>
                            <th class="px-3 py-2">Campaña</th>
                            <th class="px-3 py-2">Vigencia</th>
                            <th class="px-3 py-2">Estado</th>
                            <th class="px-3 py-2 text-right">Gasto</th>
                            <th class="px-3 py-2 text-right">Llegaron</th>
                            <th class="px-3 py-2 text-right">En proceso</th>
                            <th class="px-3 py-2 text-right">No continuaron</th>
                            <th class="px-3 py-2 text-right">Contratados</th>
                            <th class="px-3 py-2 text-right">$ / candidato</th>
                            <th class="px-3 py-2 text-right">$ / contratado</th>
                            <th class="px-3 py-2 text-right">Conversión</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="c in reporte.campanas" :key="c.id">
                            <tr
                                class="cursor-pointer border-t hover:bg-crema/50"
                                @click="
                                    abierta = abierta === c.id ? null : c.id
                                "
                            >
                                <td class="px-3 py-2">
                                    <p class="font-medium">{{ c.nombre }}</p>
                                    <p class="text-xs text-muted-foreground">
                                        {{ c.canal }} · {{ c.vacante }}
                                        <template v-if="c.responsable">
                                            · {{ c.responsable }}</template
                                        >
                                    </p>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap">
                                    {{ c.fecha_inicio ?? '—' }} –
                                    {{ c.fecha_fin ?? 'sin fin' }}
                                </td>
                                <td class="px-3 py-2">{{ c.estado }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ dinero(c.gasto) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ c.llegaron }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ c.en_proceso }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ c.no_continuaron }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ c.contratados }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ dinero(c.costo_por_candidato) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ dinero(c.costo_por_contratado) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ pct(c.conversion) }}
                                </td>
                            </tr>
                            <tr
                                v-if="abierta === c.id && conCandidatos"
                                class="bg-crema/40"
                            >
                                <td colspan="11" class="px-3 py-3">
                                    <p
                                        v-if="c.candidatos.length === 0"
                                        class="text-sm text-muted-foreground"
                                    >
                                        Ningún candidato de esta campaña con
                                        estos filtros.
                                    </p>
                                    <table v-else class="w-full text-xs">
                                        <thead class="text-left">
                                            <tr>
                                                <th class="py-1">Candidato</th>
                                                <th class="py-1">Registrado</th>
                                                <th class="py-1">
                                                    Fase alcanzada
                                                </th>
                                                <th class="py-1">Situación</th>
                                                <th class="py-1">
                                                    ¿Por qué no continuó?
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="(
                                                    cand, i
                                                ) in c.candidatos"
                                                :key="i"
                                                class="border-t"
                                            >
                                                <td class="py-1">
                                                    {{ cand.nombre }}
                                                </td>
                                                <td class="py-1">
                                                    {{ cand.registrado ?? '—' }}
                                                </td>
                                                <td class="py-1">
                                                    {{ cand.fase_alcanzada }}
                                                </td>
                                                <td class="py-1">
                                                    <span
                                                        class="rounded-full px-2 py-0.5"
                                                        :class="
                                                            cand.salio
                                                                ? 'bg-danger-soft'
                                                                : cand.sigue
                                                                  ? 'bg-warning-soft'
                                                                  : 'bg-success-soft'
                                                        "
                                                        >{{ cand.estado }}</span
                                                    >
                                                </td>
                                                <td class="py-1">
                                                    {{ cand.motivo ?? '' }}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-crema font-semibold">
                        <tr class="border-t">
                            <td class="px-3 py-2" colspan="3">Total</td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ dinero(t.gasto) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ t.llegaron }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ t.en_proceso }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ t.no_continuaron }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ t.contratados }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ dinero(t.costo_por_candidato) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ dinero(t.costo_por_contratado) }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ pct(t.conversion) }}
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </section>

            <p class="text-xs text-muted-foreground">
                <template v-if="conCandidatos"
                    >Toca una campaña para ver sus candidatos. </template
                >Costo por candidato = gasto ÷ candidatos incluidos. Si filtras
                por fecha de registro o fase del candidato (o tu alcance no
                abarca todas las sucursales), los contratados se cuentan solo
                entre los candidatos incluidos; el gasto de cada campaña es
                siempre el total capturado.
            </p>
        </template>
    </div>
</template>
