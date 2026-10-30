<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDown,
    ArrowUp,
    BarChart3,
    ChevronDown,
    FileSpreadsheet,
    FileText,
    History,
    Minus,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import PestanasBajasNav from '@/components/bajas/PestanasBajas.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { claseForma, porcentaje } from '@/lib/bajas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as rotacionHistorica } from '@/routes/reportes/rotacion';
import rutasTablero from '@/routes/reportes/rotacion/tablero';
import rutasBajas from '@/routes/rh/bajas';
import type {
    MesRotacion,
    PestanasBajas,
    SemaforoRotacion,
    TableroRotacion,
    VistaRotacion,
} from '@/types';

/**
 * Pestaña Rotación de Bajas (PDF Etapa 1, págs. 12, 13 y 17). Los datos salen del
 * motor único TableroRotacionService: histórico del Excel antes del corte y bajas
 * del sistema, por último día, desde el corte. Los meses del Excel se pintan como
 * «sin desglose» porque no traen forma de salida.
 */
const props = defineProps<{
    pestanas: PestanasBajas;
    tablero: TableroRotacion;
    puedeCargarExcel: boolean;
    puedeVerHistorico: boolean;
    puedeEditarMetas: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Bajas', href: rutasBajas.index() },
            { title: 'Rotación', href: '' },
        ],
    },
});

const vistas: { valor: VistaRotacion; etiqueta: string }[] = [
    { valor: 'global', etiqueta: 'Global' },
    { valor: 'corporativo', etiqueta: 'Corporativo' },
    { valor: 'sucursales', etiqueta: 'Todas las sucursales' },
    { valor: 'por_sucursal', etiqueta: 'Por sucursal' },
    { valor: 'por_causales', etiqueta: 'Por causales' },
];

const f = props.tablero.filtros;
const vista = ref<VistaRotacion>(f.vista);
const desde = ref(f.desde);
const hasta = ref(f.hasta);
const sucursal = ref<number | null>(f.sucursal_id);
const puesto = ref<number | null>(f.puesto_id);
const forma = ref<string>(f.forma ?? '');

const consulta = computed(() => ({
    vista: vista.value,
    desde: desde.value || undefined,
    hasta: hasta.value || undefined,
    sucursal_id: conFiltroSucursal.value
        ? (sucursal.value ?? undefined)
        : undefined,
    puesto_id: puesto.value ?? undefined,
    forma: forma.value || undefined,
}));

const conFiltroSucursal = computed(
    () => vista.value !== 'global' && vista.value !== 'corporativo',
);
const esDetalle = computed(
    () => vista.value === 'por_sucursal' || vista.value === 'por_causales',
);

watch([vista, desde, hasta, sucursal, puesto, forma], () =>
    router.get(rutasBajas.rotacion.url(), consulta.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    }),
);

const opcionesSucursal = computed(() =>
    props.tablero.opciones.sucursales.map((s) => ({
        value: s.id,
        label: s.nombre,
    })),
);
const opcionesPuesto = computed(() =>
    props.tablero.opciones.puestos.map((p) => ({
        value: p.id,
        label: p.nombre,
    })),
);

const urlExcel = computed(() =>
    rutasTablero.excel.url({ query: consulta.value }),
);
const urlPdf = computed(() => rutasTablero.pdf.url({ query: consulta.value }));

const resumen = computed(() => props.tablero.resumen);

const CHIP: Record<SemaforoRotacion, string> = {
    verde: 'bg-semaforo-baja text-white',
    ambar: 'bg-semaforo-ambar text-white',
    rojo: 'bg-semaforo-sube text-white',
};
const VALOR: Record<SemaforoRotacion, string> = {
    verde: 'text-semaforo-baja',
    ambar: 'text-semaforo-ambar',
    rojo: 'text-semaforo-sube',
};
const BORDE: Record<SemaforoRotacion, string> = {
    verde: 'border-l-semaforo-baja',
    ambar: 'border-l-semaforo-ambar',
    rojo: 'border-l-semaforo-sube',
};
const claseChip = (s: SemaforoRotacion | null) =>
    s ? CHIP[s] : 'bg-muted text-muted-foreground';
const claseValor = (s: SemaforoRotacion | null) =>
    s ? VALOR[s] : 'text-foreground';
const bordeSemaforo = (s: SemaforoRotacion | null) =>
    s ? BORDE[s] : 'border-l-border';

const tarjetas = computed(() => {
    const r = resumen.value;

    return [
        {
            etiqueta: `Rotación del mes · ${r.mes.etiqueta}${r.mes.en_curso ? ' (en curso)' : ''}`,
            valor: porcentaje(r.mes.indice),
            detalle:
                r.mes.indice === null
                    ? 'Sin datos del mes'
                    : `${r.mes.bajas ?? '—'} bajas · meta ${porcentaje(r.mes.meta?.verde_hasta)}`,
            semaforo: r.mes.semaforo,
        },
        {
            etiqueta: 'Promedio mensual',
            valor: porcentaje(r.promedio_mensual.indice),
            detalle: `${r.promedio_mensual.meses} ${r.promedio_mensual.meses === 1 ? 'mes' : 'meses'} con datos`,
            semaforo: r.promedio_mensual.semaforo,
        },
        {
            etiqueta: `Acumulada ${r.acumulado.anio}`,
            valor: porcentaje(r.acumulado.indice),
            detalle: `${r.acumulado.bajas} bajas · plantilla promedio ${r.acumulado.plantilla_promedio ?? '—'}`,
            semaforo: null,
        },
        {
            etiqueta: 'Rotación voluntaria',
            valor: porcentaje(r.voluntaria.porcentaje_voluntaria),
            detalle: `${r.voluntaria.voluntarias} de ${r.voluntaria.voluntarias + r.voluntaria.involuntarias} clasificadas`,
            semaforo: null,
        },
        {
            etiqueta: 'Rotación temprana',
            valor: porcentaje(r.rotacion_temprana.porcentaje),
            detalle: `${r.rotacion_temprana.bajas} de ${r.bajas_sistema} bajas del sistema, menos de 90 días`,
            semaforo: null,
        },
    ];
});

/* Gráfica: barras apiladas (eje izquierdo), índice y meta (eje derecho). */
const ANCHO = 900;
const ALTO = 320;
const MARGEN = { izq: 34, der: 44, arriba: 24, abajo: 44 };
const areaAlto = ALTO - MARGEN.arriba - MARGEN.abajo;
const areaAncho = ANCHO - MARGEN.izq - MARGEN.der;

const CAPAS: { clave: 'RV' | 'NR' | 'RE' | 'OT'; clase: string }[] = [
    { clave: 'RV', clase: 'fill-forma-renuncia' },
    { clave: 'NR', clase: 'fill-forma-no-renovacion' },
    { clave: 'RE', clase: 'fill-forma-rescision' },
    { clave: 'OT', clase: 'fill-forma-otra' },
];

const techoBajas = computed(() =>
    Math.max(4, ...props.tablero.tendencia.map((m) => m.bajas ?? 0)),
);
const techoIndice = computed(() =>
    Math.ceil(
        Math.max(
            5,
            ...props.tablero.tendencia.flatMap((m) => [
                m.indice ?? 0,
                m.meta?.verde_hasta ?? 0,
            ]),
        ) * 1.15,
    ),
);
const pasoX = computed(
    () => areaAncho / Math.max(1, props.tablero.tendencia.length),
);
const centroX = (i: number) => MARGEN.izq + pasoX.value * (i + 0.5);
const yBajas = (v: number) =>
    MARGEN.arriba + areaAlto - (v / techoBajas.value) * areaAlto;
const yIndice = (v: number) =>
    MARGEN.arriba + areaAlto - (v / techoIndice.value) * areaAlto;
const anchoBarra = computed(() => Math.min(38, pasoX.value * 0.6));

function segmentos(m: MesRotacion) {
    const capas = [
        ...CAPAS.map((c) => ({
            clase: c.clase,
            valor: m.por_forma[c.clave],
        })),
        { clase: 'fill-muted-foreground/40', valor: m.sin_desglose },
    ];
    let acumulado = 0;

    return capas
        .filter((c) => c.valor > 0)
        .map((c) => {
            const y = yBajas(acumulado + c.valor);
            const alto = yBajas(acumulado) - y;
            acumulado += c.valor;

            return { clase: c.clase, y, alto };
        });
}

/** Línea del índice: los meses sin datos cortan el trazo, nunca caen a 0. */
const lineasIndice = computed(() => {
    const tramos: string[] = [];
    let tramo: string[] = [];

    props.tablero.tendencia.forEach((m, i) => {
        if (m.indice === null) {
            if (tramo.length) {
                tramos.push(tramo.join(' '));
            }

            tramo = [];

            return;
        }

        tramo.push(`${centroX(i)},${yIndice(m.indice)}`);
    });

    if (tramo.length) {
        tramos.push(tramo.join(' '));
    }

    return tramos;
});

/** La meta puede cambiar con su vigencia: la línea escalona por mes. */
const lineaMeta = computed(() =>
    props.tablero.tendencia
        .flatMap((m, i) =>
            m.meta === null
                ? []
                : [
                      `${MARGEN.izq + pasoX.value * i},${yIndice(m.meta.verde_hasta)}`,
                      `${MARGEN.izq + pasoX.value * (i + 1)},${yIndice(m.meta.verde_hasta)}`,
                  ],
        )
        .join(' '),
);
const metaVisible = computed(
    () => props.tablero.tendencia.at(-1)?.meta?.verde_hasta ?? null,
);
const hayDesgloseExcel = computed(() =>
    props.tablero.tendencia.some((m) => m.sin_desglose > 0),
);

/* Dona voluntaria contra involuntaria. */
const donaSegmentos = computed(() => {
    const v = resumen.value.voluntaria;
    const partes = [
        {
            etiqueta: 'Voluntaria',
            valor: v.voluntarias,
            trazo: 'stroke-forma-renuncia',
            punto: 'bg-forma-renuncia',
        },
        {
            etiqueta: 'Involuntaria',
            valor: v.involuntarias,
            trazo: 'stroke-forma-rescision',
            punto: 'bg-forma-rescision',
        },
        {
            etiqueta: 'Otras terminaciones',
            valor: v.otras,
            trazo: 'stroke-forma-otra',
            punto: 'bg-forma-otra',
        },
        {
            etiqueta: 'Sin clasificar (Excel)',
            valor: v.sin_clasificar,
            trazo: 'stroke-muted-foreground/40',
            punto: 'bg-muted-foreground/40',
        },
    ];
    const total = Math.max(1, v.total);
    let inicio = 0;

    return partes.map((p) => {
        const parte = (p.valor / total) * 100;
        const segmento = { ...p, porcentaje: parte, desfase: -inicio };
        inicio += parte;

        return segmento;
    });
});

/* Por sucursal: detalle de las bajas que componen el indicador. */
const abierta = ref<number | null>(null);
const maxCausal = computed(() =>
    Math.max(1, ...props.tablero.causales.map((c) => c.bajas)),
);
const alertasCausal = computed(() =>
    props.tablero.causales.filter((c) => c.alerta),
);

/* Metas: una edición agrega una fila con vigencia; nunca toca meses pasados. */
const editandoMetas = ref(false);
const verHistorial = ref(false);
const formMeta = useForm({
    segmento: 'global',
    verde_hasta: '',
    ambar_hasta: '',
    vigente_desde: f.hasta,
    motivo: '',
});
const opcionesSegmento = computed(() =>
    props.tablero.metas.vigentes.map((m) => ({
        value: m.segmento,
        label: m.etiqueta,
    })),
);

function guardarMeta() {
    formMeta.post(rutasBajas.rotacionMetas.url(), {
        preserveScroll: true,
        onSuccess: () => {
            formMeta.reset('verde_hasta', 'ambar_hasta', 'motivo');
            editandoMetas.value = false;
        },
    });
}
</script>

<template>
    <Head title="Rotación de personal" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader titulo="Rotación de personal" :icono="BarChart3">
            <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                <Button variant="outline" as-child>
                    <a :href="urlExcel"
                        ><FileSpreadsheet class="size-4" />Excel</a
                    >
                </Button>
                <Button variant="outline" as-child>
                    <a :href="urlPdf"
                        ><FileText class="size-4" />PDF para Dirección</a
                    >
                </Button>
                <Button
                    v-if="puedeVerHistorico"
                    variant="outline"
                    class="col-span-2"
                    as-child
                >
                    <Link :href="rotacionHistorica()"
                        ><History class="size-4" />{{
                            puedeCargarExcel
                                ? 'Histórico (cargar Excel)'
                                : 'Histórico del Excel'
                        }}</Link
                    >
                </Button>
            </div>
        </CrudPageHeader>

        <PestanasBajasNav :pestanas="pestanas" actual="rotacion" />

        <section
            class="flex flex-col gap-3 xl:flex-row xl:items-end xl:justify-between"
            aria-label="Vistas y filtros"
        >
            <nav
                class="-mx-1 scroll-x-limpio flex max-w-full gap-2 px-1 py-0.5"
                aria-label="Vistas de rotación"
            >
                <button
                    v-for="v in vistas"
                    :key="v.valor"
                    type="button"
                    :class="
                        cn(
                            'h-9 shrink-0 rounded-full px-4 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
                            vista === v.valor
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'bg-card text-muted-foreground ring-1 ring-border hover:text-foreground',
                        )
                    "
                    :aria-current="vista === v.valor ? 'page' : undefined"
                    @click="vista = v.valor"
                >
                    {{ v.etiqueta }}
                </button>
            </nav>
            <div
                class="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap sm:items-end"
            >
                <div class="min-w-0">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Desde</Label
                    >
                    <MonthPicker v-model="desde" aria-label="Desde" />
                </div>
                <div class="min-w-0">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Hasta</Label
                    >
                    <MonthPicker v-model="hasta" aria-label="Hasta" />
                </div>
                <div v-if="conFiltroSucursal" class="min-w-0 sm:w-44">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Sucursal</Label
                    >
                    <SelectSimple
                        v-model="sucursal"
                        :opciones="opcionesSucursal"
                        numerico
                        opcion-vacia="Todas"
                        placeholder="Todas"
                    />
                </div>
                <div class="min-w-0 sm:w-44">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Puesto</Label
                    >
                    <SelectSimple
                        v-model="puesto"
                        :opciones="opcionesPuesto"
                        numerico
                        opcion-vacia="Todos"
                        placeholder="Todos"
                    />
                </div>
                <div class="min-w-0 sm:w-44">
                    <Label class="mb-1 text-xs text-muted-foreground"
                        >Forma de salida</Label
                    >
                    <SelectSimple
                        v-model="forma"
                        :opciones="tablero.opciones.formas"
                        opcion-vacia="Todas"
                        placeholder="Todas"
                    />
                </div>
            </div>
        </section>

        <!-- Global, Corporativo y Todas las sucursales -->
        <template v-if="!esDetalle">
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
                <article
                    v-for="t in tarjetas"
                    :key="t.etiqueta"
                    :class="
                        cn(
                            'flex flex-col gap-1 rounded-2xl border border-l-4 border-border/60 bg-card p-4 shadow-sm',
                            bordeSemaforo(t.semaforo),
                        )
                    "
                >
                    <p class="text-xs text-muted-foreground">
                        {{ t.etiqueta }}
                    </p>
                    <p
                        :class="
                            cn(
                                'text-2xl font-bold tabular-nums',
                                claseValor(t.semaforo),
                            )
                        "
                    >
                        {{ t.valor }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        {{ t.detalle }}
                    </p>
                </article>
            </div>

            <div class="flex flex-wrap gap-x-6 gap-y-1 text-xs">
                <span class="inline-flex items-center gap-1">
                    Vs. mes anterior:
                    <template v-if="resumen.comparativo.tendencia === 'sube'">
                        <ArrowUp class="size-3.5 text-semaforo-sube" />
                        <strong class="text-semaforo-sube tabular-nums"
                            >+{{ resumen.comparativo.delta }} pts.</strong
                        >
                    </template>
                    <template
                        v-else-if="resumen.comparativo.tendencia === 'baja'"
                    >
                        <ArrowDown class="size-3.5 text-semaforo-baja" />
                        <strong class="text-semaforo-baja tabular-nums"
                            >{{ resumen.comparativo.delta }} pts.</strong
                        >
                    </template>
                    <template
                        v-else-if="resumen.comparativo.tendencia === 'igual'"
                    >
                        <Minus class="size-3.5" />Igual
                    </template>
                    <template v-else>sin datos para comparar</template>
                </span>
                <span>
                    Causal principal:
                    <strong>{{
                        resumen.causal_principal
                            ? `${resumen.causal_principal.clave ?? ''} ${resumen.causal_principal.nombre} (${resumen.causal_principal.bajas})`
                            : '—'
                    }}</strong>
                </span>
                <span
                    :class="
                        !resumen.comprobacion.cuadra && 'text-semaforo-sube'
                    "
                >
                    Corporativo {{ resumen.comprobacion.corporativo }} +
                    Sucursales {{ resumen.comprobacion.sucursales }} = Global
                    {{ resumen.comprobacion.global }} bajas
                </span>
            </div>

            <div
                class="grid gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(18rem,1fr)]"
            >
                <section
                    class="flex min-w-0 flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                >
                    <div>
                        <h2 class="font-semibold">
                            Bajas por forma de salida e índice mensual
                        </h2>
                        <p class="text-xs text-muted-foreground">
                            {{ tablero.segmento.etiqueta }} · el índice usa la
                            plantilla promedio del mes
                        </p>
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
                        <span
                            v-for="fo in tablero.opciones.formas"
                            :key="fo.value"
                            class="flex items-center gap-1.5"
                            ><span
                                :class="
                                    cn(
                                        'size-2.5 rounded-full',
                                        claseForma(fo.token).fondo,
                                    )
                                "
                            />{{ fo.label }}</span
                        >
                        <span
                            v-if="hayDesgloseExcel"
                            class="flex items-center gap-1.5"
                            ><span
                                class="size-2.5 rounded-full bg-muted-foreground/40"
                            />Histórico sin desglose</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><span class="h-0.5 w-4 bg-semaforo-sube" />Índice
                            mensual</span
                        >
                        <span class="flex items-center gap-1.5"
                            ><span
                                class="w-4 border-t-2 border-dashed border-semaforo-baja"
                            />Meta</span
                        >
                    </div>
                    <div class="tabla-scroll">
                        <svg
                            :viewBox="`0 0 ${ANCHO} ${ALTO}`"
                            class="w-full min-w-[40rem]"
                            role="img"
                            :aria-label="`Bajas e índice mensual de ${tablero.filtros.desde} a ${tablero.filtros.hasta}`"
                        >
                            <g v-for="n in 4" :key="n">
                                <line
                                    :x1="MARGEN.izq"
                                    :x2="ANCHO - MARGEN.der"
                                    :y1="yBajas((techoBajas / 4) * n)"
                                    :y2="yBajas((techoBajas / 4) * n)"
                                    class="stroke-border"
                                />
                                <text
                                    :x="MARGEN.izq - 6"
                                    :y="yBajas((techoBajas / 4) * n) + 4"
                                    text-anchor="end"
                                    class="fill-muted-foreground text-[11px]"
                                >
                                    {{ Math.round((techoBajas / 4) * n) }}
                                </text>
                                <text
                                    :x="ANCHO - MARGEN.der + 6"
                                    :y="yBajas((techoBajas / 4) * n) + 4"
                                    class="fill-semaforo-sube text-[11px]"
                                >
                                    {{ Math.round((techoIndice / 4) * n) }}%
                                </text>
                            </g>
                            <line
                                :x1="MARGEN.izq"
                                :x2="ANCHO - MARGEN.der"
                                :y1="MARGEN.arriba + areaAlto"
                                :y2="MARGEN.arriba + areaAlto"
                                class="stroke-border"
                            />
                            <g
                                v-for="(m, i) in tablero.tendencia"
                                :key="m.periodo"
                            >
                                <rect
                                    v-for="s in segmentos(m)"
                                    :key="s.clase"
                                    :x="centroX(i) - anchoBarra / 2"
                                    :y="s.y"
                                    :width="anchoBarra"
                                    :height="s.alto"
                                    :class="s.clase"
                                >
                                    <title>
                                        {{ m.etiqueta }}: {{ m.bajas }} bajas
                                    </title>
                                </rect>
                                <text
                                    v-if="m.bajas"
                                    :x="centroX(i)"
                                    :y="yBajas(m.bajas) - 4"
                                    text-anchor="middle"
                                    class="fill-muted-foreground text-[11px]"
                                >
                                    {{ m.bajas }}
                                </text>
                                <text
                                    v-if="m.indice === null"
                                    :x="centroX(i)"
                                    :y="MARGEN.arriba + areaAlto - 6"
                                    text-anchor="middle"
                                    class="fill-muted-foreground text-[10px]"
                                >
                                    sin datos
                                </text>
                                <text
                                    :x="centroX(i)"
                                    :y="MARGEN.arriba + areaAlto + 16"
                                    text-anchor="middle"
                                    class="fill-muted-foreground text-[11px]"
                                >
                                    {{ m.etiqueta }}
                                </text>
                                <text
                                    v-if="m.en_curso"
                                    :x="centroX(i)"
                                    :y="MARGEN.arriba + areaAlto + 30"
                                    text-anchor="middle"
                                    class="fill-muted-foreground text-[10px] italic"
                                >
                                    en curso
                                </text>
                            </g>
                            <polyline
                                v-if="lineaMeta"
                                :points="lineaMeta"
                                fill="none"
                                class="stroke-semaforo-baja"
                                stroke-width="2"
                                stroke-dasharray="6 5"
                            />
                            <polyline
                                v-for="tramo in lineasIndice"
                                :key="tramo"
                                :points="tramo"
                                fill="none"
                                class="stroke-semaforo-sube"
                                stroke-width="2"
                            />
                            <g
                                v-for="(m, i) in tablero.tendencia"
                                :key="m.periodo"
                            >
                                <template v-if="m.indice !== null">
                                    <circle
                                        :cx="centroX(i)"
                                        :cy="yIndice(m.indice)"
                                        r="3.5"
                                        class="fill-card stroke-semaforo-sube"
                                        stroke-width="2"
                                    />
                                    <text
                                        :x="centroX(i) + 6"
                                        :y="yIndice(m.indice) - 6"
                                        :class="
                                            cn(
                                                'text-[11px] font-semibold',
                                                m.semaforo === 'verde'
                                                    ? 'fill-semaforo-baja'
                                                    : m.semaforo === 'ambar'
                                                      ? 'fill-semaforo-ambar'
                                                      : 'fill-semaforo-sube',
                                            )
                                        "
                                    >
                                        {{ m.indice }}%
                                    </text>
                                </template>
                            </g>
                        </svg>
                    </div>
                    <p
                        v-if="metaVisible !== null"
                        class="text-xs text-muted-foreground"
                    >
                        Meta verde del último mes:
                        {{ porcentaje(metaVisible) }}. El histórico del Excel
                        llega hasta {{ tablero.mes_inicio_sistema }}; desde ese
                        mes cuentan las bajas del sistema.
                    </p>
                </section>

                <aside class="flex min-w-0 flex-col gap-4">
                    <section
                        class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <h2 class="mb-2 text-sm font-semibold">
                            Voluntaria contra involuntaria
                        </h2>
                        <div class="flex items-center gap-4">
                            <svg
                                viewBox="0 0 42 42"
                                class="size-24 shrink-0 -rotate-90"
                                role="img"
                                aria-label="Distribución de bajas por tipo"
                            >
                                <circle
                                    cx="21"
                                    cy="21"
                                    r="15.915"
                                    fill="none"
                                    class="stroke-muted"
                                    stroke-width="6"
                                />
                                <circle
                                    v-for="s in donaSegmentos"
                                    :key="s.etiqueta"
                                    cx="21"
                                    cy="21"
                                    r="15.915"
                                    fill="none"
                                    :class="s.trazo"
                                    stroke-width="6"
                                    :stroke-dasharray="`${s.porcentaje} ${100 - s.porcentaje}`"
                                    :stroke-dashoffset="s.desfase"
                                />
                            </svg>
                            <ul class="flex min-w-0 flex-col gap-1 text-xs">
                                <li class="font-semibold">
                                    {{ resumen.voluntaria.total }} bajas
                                </li>
                                <li
                                    v-for="s in donaSegmentos"
                                    :key="s.etiqueta"
                                    v-show="s.valor > 0"
                                    class="flex items-center gap-1.5"
                                >
                                    <span
                                        :class="
                                            cn(
                                                'size-2.5 shrink-0 rounded-full',
                                                s.punto,
                                            )
                                        "
                                    />{{ s.etiqueta }} {{ s.valor }} ·
                                    {{ Math.round(s.porcentaje) }}%
                                </li>
                            </ul>
                        </div>
                    </section>

                    <section
                        class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <div
                            class="mb-2 flex items-center justify-between gap-2"
                        >
                            <h2 class="text-sm font-semibold">
                                Metas mensuales del semáforo
                            </h2>
                            <Button
                                v-if="puedeEditarMetas"
                                variant="outline"
                                size="sm"
                                @click="editandoMetas = !editandoMetas"
                                >Editar</Button
                            >
                        </div>
                        <table class="w-full text-left text-xs">
                            <thead class="text-muted-foreground">
                                <tr>
                                    <th class="py-1 font-medium">Vista</th>
                                    <th class="py-1 font-medium">Verde</th>
                                    <th class="py-1 font-medium">Ámbar</th>
                                    <th class="py-1 font-medium">Rojo</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="m in tablero.metas.vigentes"
                                    :key="m.segmento"
                                    class="border-t border-border/50"
                                >
                                    <td class="py-1.5 font-medium">
                                        {{ m.etiqueta }}
                                    </td>
                                    <td class="py-1.5 tabular-nums">
                                        ≤ {{ m.verde_hasta }}%
                                    </td>
                                    <td class="py-1.5 tabular-nums">
                                        {{ m.verde_hasta }} –
                                        {{ m.ambar_hasta }}%
                                    </td>
                                    <td class="py-1.5 tabular-nums">
                                        &gt; {{ m.ambar_hasta }}%
                                    </td>
                                </tr>
                            </tbody>
                        </table>

                        <form
                            v-if="editandoMetas"
                            class="mt-3 grid gap-2 border-t border-border/50 pt-3"
                            @submit.prevent="guardarMeta"
                        >
                            <SelectSimple
                                v-model="formMeta.segmento"
                                :opciones="opcionesSegmento"
                            />
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <Label for="meta-verde" class="mb-1 text-xs"
                                        >Verde hasta %</Label
                                    >
                                    <Input
                                        id="meta-verde"
                                        v-model="formMeta.verde_hasta"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                    />
                                </div>
                                <div>
                                    <Label for="meta-ambar" class="mb-1 text-xs"
                                        >Ámbar hasta %</Label
                                    >
                                    <Input
                                        id="meta-ambar"
                                        v-model="formMeta.ambar_hasta"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                    />
                                </div>
                            </div>
                            <div>
                                <Label class="mb-1 text-xs">Aplica desde</Label>
                                <MonthPicker
                                    v-model="formMeta.vigente_desde"
                                    aria-label="Aplica desde"
                                />
                            </div>
                            <div>
                                <Label for="meta-motivo" class="mb-1 text-xs"
                                    >Motivo del cambio</Label
                                >
                                <Input
                                    id="meta-motivo"
                                    v-model="formMeta.motivo"
                                />
                            </div>
                            <p
                                v-for="(error, campo) in formMeta.errors"
                                :key="campo"
                                class="text-xs text-semaforo-sube"
                            >
                                {{ error }}
                            </p>
                            <Button
                                type="submit"
                                :disabled="formMeta.processing"
                                >Registrar meta</Button
                            >
                        </form>

                        <button
                            type="button"
                            class="mt-2 inline-flex items-center gap-1 text-xs text-muted-foreground hover:text-foreground"
                            :aria-expanded="verHistorial"
                            @click="verHistorial = !verHistorial"
                        >
                            Historial de metas
                            <ChevronDown
                                class="size-3.5 transition-transform"
                                :class="verHistorial && 'rotate-180'"
                            />
                        </button>
                        <ul
                            v-if="verHistorial"
                            class="mt-2 flex flex-col gap-2 text-xs"
                        >
                            <li
                                v-for="h in tablero.metas.historial"
                                :key="h.id"
                                class="border-t border-border/50 pt-2"
                            >
                                <strong>{{ h.segmento }}</strong>
                                · ≤ {{ h.verde_hasta }}% / ≤
                                {{ h.ambar_hasta }}% · desde
                                {{ h.vigente_desde }}
                                <p class="text-muted-foreground">
                                    {{ h.motivo }}
                                    <template v-if="h.registrado_por">
                                        · {{ h.registrado_por }}
                                    </template>
                                </p>
                            </li>
                        </ul>
                    </section>

                    <section
                        class="rounded-2xl border border-border/60 bg-success-soft p-4 text-xs"
                    >
                        <h2 class="mb-1 text-sm font-semibold">Fórmula</h2>
                        <p>
                            Rotación = bajas del periodo ÷ plantilla promedio ×
                            100
                        </p>
                        <p class="mt-1 text-muted-foreground">
                            Plantilla promedio = (plantilla al inicio +
                            plantilla al cierre) ÷ 2. El índice global no es la
                            suma de los segmentos: cada uno divide entre su
                            plantilla; lo que sí suma son las bajas.
                        </p>
                    </section>
                </aside>
            </div>
        </template>

        <!-- Por sucursal y Por causales -->
        <div
            v-else
            class="grid gap-4 xl:grid-cols-[minmax(0,7fr)_minmax(20rem,4fr)]"
        >
            <section
                :class="
                    cn(
                        'flex min-w-0 flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm',
                        vista === 'por_causales' && 'order-2 xl:order-1',
                    )
                "
            >
                <div>
                    <h2 class="font-semibold">
                        Por sucursal · {{ tablero.filtros.desde }} a
                        {{ tablero.filtros.hasta }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Semáforo sobre el promedio mensual contra la meta de
                        cada segmento. Elige una sucursal para ver sus bajas.
                    </p>
                </div>
                <EmptyState
                    v-if="!tablero.sucursales.length"
                    :icono="BarChart3"
                    titulo="Sin datos"
                    descripcion="No hay rotación registrada en el periodo."
                />
                <div v-else class="tabla-scroll">
                    <table class="w-full min-w-[52rem] text-left text-xs">
                        <thead
                            class="bg-[var(--mrl-fondo)] text-muted-foreground"
                        >
                            <tr>
                                <th class="px-2 py-2 font-medium">Sucursal</th>
                                <th class="px-2 py-2 font-medium">
                                    Plantilla prom.
                                </th>
                                <th class="px-2 py-2 font-medium">Bajas</th>
                                <th class="px-2 py-2 font-medium">
                                    Prom. mensual
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    Último mes
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    Vs. anterior
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    RV / NR / RE / OT
                                </th>
                                <th class="px-2 py-2 font-medium">
                                    Causal principal
                                </th>
                                <th class="px-2 py-2 font-medium">Temprana</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template
                                v-for="s in tablero.sucursales"
                                :key="s.sucursal_id"
                            >
                                <tr
                                    :class="
                                        cn(
                                            'cursor-pointer border-t border-border/50 hover:bg-muted/40',
                                            s.es_corporativo &&
                                                'bg-[var(--mrl-fondo)]',
                                        )
                                    "
                                    :aria-expanded="abierta === s.sucursal_id"
                                    tabindex="0"
                                    @click="
                                        abierta =
                                            abierta === s.sucursal_id
                                                ? null
                                                : s.sucursal_id
                                    "
                                    @keydown.enter="
                                        abierta =
                                            abierta === s.sucursal_id
                                                ? null
                                                : s.sucursal_id
                                    "
                                >
                                    <td class="px-2 py-2 font-semibold">
                                        {{ s.sucursal }}
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">
                                        {{ s.plantilla_promedio ?? '—' }}
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">
                                        {{ s.bajas }}
                                    </td>
                                    <td class="px-2 py-2">
                                        <span
                                            :class="
                                                cn(
                                                    'inline-block rounded-full px-2 py-0.5 font-semibold tabular-nums',
                                                    claseChip(s.semaforo),
                                                )
                                            "
                                            >{{
                                                porcentaje(s.promedio_mensual)
                                            }}</span
                                        >
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">
                                        {{ porcentaje(s.indice_mes) }}
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">
                                        <span
                                            v-if="s.tendencia === 'sube'"
                                            class="inline-flex items-center gap-0.5 text-semaforo-sube"
                                            ><ArrowUp class="size-3.5" />{{
                                                s.delta
                                            }}</span
                                        >
                                        <span
                                            v-else-if="s.tendencia === 'baja'"
                                            class="inline-flex items-center gap-0.5 text-semaforo-baja"
                                            ><ArrowDown class="size-3.5" />{{
                                                Math.abs(s.delta ?? 0)
                                            }}</span
                                        >
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >{{
                                                s.tendencia === 'igual'
                                                    ? '='
                                                    : '—'
                                            }}</span
                                        >
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">
                                        {{ s.conteos.RV }} /
                                        {{ s.conteos.NR }} /
                                        {{ s.conteos.RE }} / {{ s.conteos.OT }}
                                        <span
                                            v-if="s.conteos.sin_desglose"
                                            class="text-muted-foreground"
                                            >(+{{
                                                s.conteos.sin_desglose
                                            }}
                                            Excel)</span
                                        >
                                    </td>
                                    <td class="max-w-48 px-2 py-2">
                                        {{ s.causal_principal ?? '—' }}
                                    </td>
                                    <td class="px-2 py-2 tabular-nums">
                                        {{ porcentaje(s.rotacion_temprana) }}
                                    </td>
                                </tr>
                                <tr v-if="abierta === s.sucursal_id">
                                    <td
                                        colspan="9"
                                        class="bg-[var(--mrl-fondo)] px-3 py-2"
                                    >
                                        <p
                                            v-if="!s.bajas_lista.length"
                                            class="text-muted-foreground"
                                        >
                                            Sin bajas del sistema en el periodo.
                                        </p>
                                        <ul v-else class="flex flex-col gap-1">
                                            <li
                                                v-for="b in s.bajas_lista"
                                                :key="b.id"
                                            >
                                                <Link
                                                    :href="
                                                        rutasBajas.show(b.id)
                                                    "
                                                    class="font-medium text-primary underline-offset-2 hover:underline"
                                                    >{{ b.folio }} ·
                                                    {{ b.colaborador }}</Link
                                                >
                                                · {{ b.fecha_baja }} ·
                                                {{ b.causal ?? b.forma }} ·
                                                {{ b.estatus }}
                                            </li>
                                        </ul>
                                        <p
                                            v-if="s.conteos.sin_desglose"
                                            class="mt-1 text-muted-foreground"
                                        >
                                            {{ s.conteos.sin_desglose }} baja(s)
                                            del histórico de Excel no tienen
                                            detalle por persona.
                                        </p>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-muted-foreground">
                    Flecha roja: la rotación subió contra el mes anterior.
                    Flecha verde: bajó.
                </p>
            </section>

            <section
                :class="
                    cn(
                        'flex min-w-0 flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm',
                        vista === 'por_causales' && 'order-1 xl:order-2',
                    )
                "
            >
                <div>
                    <h2 class="font-semibold">
                        Por causales · {{ resumen.bajas_sistema }} bajas
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Bajas del sistema del periodo; el histórico de Excel no
                        trae causal.
                    </p>
                </div>
                <EmptyState
                    v-if="!tablero.causales.length"
                    :icono="BarChart3"
                    titulo="Sin bajas"
                    descripcion="No hay bajas del sistema en el periodo."
                />
                <ul class="flex flex-col gap-2.5">
                    <li
                        v-for="c in tablero.causales"
                        :key="`${c.clave}-${c.nombre}`"
                        class="flex flex-col gap-1 text-xs"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="min-w-0"
                                >{{ c.clave }} {{ c.nombre
                                }}<AlertTriangle
                                    v-if="c.alerta"
                                    class="ml-1 inline size-3.5 text-semaforo-ambar"
                                    aria-label="Causal con alerta"
                            /></span>
                            <strong class="shrink-0 tabular-nums"
                                >{{ c.bajas }} ·
                                {{ porcentaje(c.porcentaje) }}</strong
                            >
                        </div>
                        <div class="h-2 overflow-hidden rounded-full bg-muted">
                            <div
                                :class="
                                    cn(
                                        'h-full rounded-full',
                                        claseForma(c.token).fondo,
                                    )
                                "
                                :style="{
                                    width: `${(c.bajas / maxCausal) * 100}%`,
                                }"
                            />
                        </div>
                    </li>
                </ul>
                <p
                    v-for="c in alertasCausal"
                    :key="`alerta-${c.clave}`"
                    class="rounded-xl bg-warning-soft p-2 text-xs"
                >
                    <AlertTriangle
                        class="mr-1 inline size-3.5 text-semaforo-ambar"
                    />{{ c.clave }}: {{ c.alerta }}
                </p>
            </section>
        </div>
    </div>
</template>
