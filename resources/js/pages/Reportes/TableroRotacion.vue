<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    BarChart3,
    Briefcase,
    Building2,
    CalendarClock,
    ChevronDown,
    FileCheck2,
    FileSpreadsheet,
    FileText,
    History,
    LayoutDashboard,
    ListTree,
    Minus,
    Percent,
    SlidersHorizontal,
    TrendingUp,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import PestanasBajasNav from '@/components/bajas/PestanasBajas.vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { claseForma, porcentaje } from '@/lib/bajas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as rotacionHistorica } from '@/routes/reportes/rotacion';
import rutasTablero from '@/routes/reportes/rotacion/tablero';
import rutasBajas from '@/routes/rh/bajas';
import type { MesRotacion, PestanasBajas, TableroRotacion } from '@/types';

/**
 * Tablero de rotación (especificación de RH §9). Combina dos fuentes:
 * los meses anteriores al módulo salen del Excel de rotación ya importado
 * (agregados por sucursal) y, desde el mes de inicio del sistema, de las
 * bajas cerradas del módulo (es_rotacion) con la plantilla mensual. El
 * desglose por forma, causal y puesto solo existe para bajas del sistema.
 * Colores por forma: tokens --forma-salida-* (resources/css/app.css).
 */
const props = defineProps<{
    pestanas: PestanasBajas;
    tablero: TableroRotacion;
    puedeCargarExcel: boolean;
    puedeVerHistorico: boolean;
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

type Vista = 'resumen' | 'tendencia' | 'causales' | 'sucursales' | 'puestos';
const vista = ref<Vista>('resumen');
const vistas: { valor: Vista; etiqueta: string; icono: Component }[] = [
    { valor: 'resumen', etiqueta: 'Resumen', icono: LayoutDashboard },
    { valor: 'tendencia', etiqueta: '12 meses', icono: TrendingUp },
    { valor: 'causales', etiqueta: 'Causales', icono: ListTree },
    { valor: 'sucursales', etiqueta: 'Sucursales', icono: Building2 },
    { valor: 'puestos', etiqueta: 'Puestos', icono: Briefcase },
];

/** En móvil los filtros secundarios van plegados. */
const verFiltros = ref(false);

const f = props.tablero.filtros;
const desde = ref(f.desde);
const hasta = ref(f.hasta);
const sucursal = ref<number | null>(f.sucursal_id);
const puesto = ref<number | null>(f.puesto_id);
const forma = ref<string>(f.forma ?? '');
const causal = ref<number | null>(f.causal_id);

const consulta = computed(() => ({
    desde: desde.value || undefined,
    hasta: hasta.value || undefined,
    sucursal_id: sucursal.value ?? undefined,
    puesto_id: puesto.value ?? undefined,
    forma: forma.value || undefined,
    causal_id: causal.value ?? undefined,
}));

watch([desde, hasta, sucursal, puesto, forma, causal], () =>
    router.get(rutasTablero.index.url(), consulta.value, {
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
const opcionesCausal = computed(() =>
    props.tablero.opciones.causales
        .filter((c) => !forma.value || c.forma === forma.value)
        .map((c) => ({
            value: c.id,
            label: `${c.clave ?? ''} ${c.nombre}`.trim(),
        })),
);

const urlExcel = computed(() =>
    rutasTablero.excel.url({ query: consulta.value }),
);
const urlPdf = computed(() => rutasTablero.pdf.url({ query: consulta.value }));

const resumen = computed(() => props.tablero.resumen);

const fuenteMes = (m: MesRotacion) =>
    ({
        excel: 'Excel de rotación',
        excel_sin_desglose: 'Excel (sin desglose por forma/causal/puesto)',
        sistema: 'Bajas del sistema',
        sin_plantilla: 'Sin plantilla registrada',
        sin_datos: 'Sin datos',
    })[m.fuente] ?? m.fuente;

// Tendencia: barras verticales con escala común (un solo eje).
const maxIndice = computed(() =>
    Math.max(
        1,
        ...props.tablero.tendencia.flatMap((m) => [
            m.indice ?? 0,
            m.indice_sucursal ?? 0,
        ]),
    ),
);
const maxCausal = computed(() =>
    Math.max(1, ...props.tablero.causales.map((c) => c.bajas)),
);
const conSucursal = computed(() => sucursal.value !== null);
const nombreSucursal = computed(
    () =>
        props.tablero.opciones.sucursales.find((s) => s.id === sucursal.value)
            ?.nombre ?? '',
);

const filtrosSecundarios = computed(
    () =>
        [
            sucursal.value,
            puesto.value,
            forma.value || null,
            causal.value,
        ].filter((v) => v !== null).length,
);

const claseTab = (activo: boolean) =>
    cn(
        'inline-flex h-10 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
        activo
            ? 'bg-primary text-primary-foreground shadow-sm'
            : 'bg-card text-muted-foreground ring-1 ring-border hover:text-foreground',
    );

/** Móvil: tarjeta solo para sucursales con bajas; las demás, en chips. */
const sucursalesConBajas = computed(() =>
    props.tablero.sucursales.filter((s) => s.bajas > 0),
);
const sucursalesSinBajas = computed(() =>
    props.tablero.sucursales.filter((s) => s.bajas === 0),
);

const antiguedadDe = (formaClave: string) =>
    props.tablero.resumen.antiguedad_por_forma.find(
        (a) => a.forma === formaClave,
    )?.dias ?? null;
</script>

<template>
    <Head title="Rotación de personal" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader titulo="Rotación de personal" :icono="BarChart3">
            <div class="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto">
                <Button variant="outline" as-child>
                    <a :href="urlExcel"
                        ><FileSpreadsheet class="size-4" />Excel</a
                    >
                </Button>
                <Button variant="outline" as-child>
                    <a :href="urlPdf"><FileText class="size-4" />PDF</a>
                </Button>
                <Button
                    v-if="puedeVerHistorico"
                    variant="ghost"
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

        <!-- Filtros: periodo siempre a la vista; el resto plegable en móvil -->
        <section
            class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-3 shadow-sm sm:p-4"
            aria-label="Filtros del tablero"
        >
            <div
                class="grid grid-cols-2 gap-2 sm:gap-3 lg:grid-cols-6 lg:items-end"
            >
                <div class="min-w-0">
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Desde</Label
                    >
                    <MonthPicker
                        v-model="desde"
                        aria-label="Desde"
                        class="w-full"
                    />
                </div>
                <div class="min-w-0">
                    <Label class="mb-1.5 text-xs text-muted-foreground"
                        >Hasta</Label
                    >
                    <MonthPicker
                        v-model="hasta"
                        aria-label="Hasta"
                        class="w-full"
                    />
                </div>

                <Button
                    variant="outline"
                    class="col-span-2 lg:hidden"
                    :aria-expanded="verFiltros"
                    @click="verFiltros = !verFiltros"
                >
                    <SlidersHorizontal class="size-4" />
                    Más filtros
                    <span
                        v-if="filtrosSecundarios"
                        class="rounded-full bg-primary px-1.5 text-xs text-primary-foreground tabular-nums"
                        >{{ filtrosSecundarios }}</span
                    >
                    <ChevronDown
                        class="ml-auto size-4 transition-transform"
                        :class="verFiltros && 'rotate-180'"
                    />
                </Button>

                <div
                    :class="
                        cn(
                            'col-span-2 grid-cols-1 gap-2 sm:grid-cols-2 sm:gap-3 lg:col-span-4 lg:grid lg:grid-cols-4',
                            verFiltros ? 'grid' : 'hidden',
                        )
                    "
                >
                    <div class="min-w-0">
                        <Label class="mb-1.5 text-xs text-muted-foreground"
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
                    <div class="min-w-0">
                        <Label class="mb-1.5 text-xs text-muted-foreground"
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
                    <div class="min-w-0">
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Forma de salida</Label
                        >
                        <SelectSimple
                            v-model="forma"
                            :opciones="tablero.opciones.formas"
                            opcion-vacia="Todas"
                            placeholder="Todas"
                        />
                    </div>
                    <div class="min-w-0">
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Causal</Label
                        >
                        <SelectSimple
                            v-model="causal"
                            :opciones="opcionesCausal"
                            numerico
                            opcion-vacia="Todas"
                            placeholder="Todas"
                        />
                    </div>
                </div>
            </div>
        </section>

        <nav
            class="-mx-1 scroll-x-limpio flex max-w-full gap-2 px-1 py-0.5"
            aria-label="Vistas del tablero"
        >
            <button
                v-for="v in vistas"
                :key="v.valor"
                type="button"
                :class="claseTab(vista === v.valor)"
                :aria-current="vista === v.valor ? 'page' : undefined"
                @click="vista = v.valor"
            >
                <component :is="v.icono" class="size-4" />
                {{ v.etiqueta }}
            </button>
        </nav>

        <!-- Resumen -->
        <template v-if="vista === 'resumen'">
            <div class="grid grid-cols-2 gap-2.5 sm:gap-3 xl:grid-cols-4">
                <TarjetaMetrica
                    :etiqueta="`Índice · ${resumen.mes.etiqueta}`"
                    :valor="porcentaje(resumen.mes.indice)"
                    :icono="Percent"
                    tono="primario"
                    :detalle="`${resumen.mes.bajas ?? '—'} bajas · plantilla ${resumen.mes.plantilla_promedio ?? '—'}`"
                />
                <TarjetaMetrica
                    :etiqueta="`Acumulado ${resumen.acumulado.anio}`"
                    :valor="porcentaje(resumen.acumulado.indice)"
                    :icono="TrendingUp"
                    tono="oro"
                    :detalle="`${resumen.acumulado.bajas} bajas · plantilla ${resumen.acumulado.plantilla_promedio ?? '—'}`"
                />
                <TarjetaMetrica
                    etiqueta="Temprana (< 90 días)"
                    :valor="porcentaje(resumen.rotacion_temprana.porcentaje)"
                    :icono="CalendarClock"
                    tono="alerta"
                    :detalle="`${resumen.rotacion_temprana.bajas} de ${resumen.bajas_sistema} bajas`"
                />
                <TarjetaMetrica
                    etiqueta="Expediente completo"
                    :valor="porcentaje(resumen.calidad_expediente.porcentaje)"
                    :icono="FileCheck2"
                    tono="exito"
                    :detalle="`${resumen.calidad_expediente.completas} de ${resumen.calidad_expediente.cerradas} cerradas`"
                />
            </div>

            <section class="flex flex-col gap-2">
                <div class="flex items-center gap-1.5">
                    <h2 class="text-sm font-semibold text-muted-foreground">
                        Por forma de salida
                    </h2>
                    <AyudaBoton
                        titulo="Cómo se calcula"
                        :texto="`Índice = bajas ÷ plantilla promedio × 100. Hasta ${tablero.mes_inicio_sistema} se usa el Excel de rotación (solo totales por sucursal); desde ese mes, las bajas cerradas del sistema, que traen el desglose por forma, causal, puesto, antigüedad y expediente.`"
                    />
                    <span class="ml-auto text-xs text-muted-foreground">
                        Antigüedad promedio al salir:
                        <strong class="text-foreground tabular-nums"
                            >{{
                                resumen.antiguedad_promedio_dias ?? '—'
                            }}
                            días</strong
                        >
                    </span>
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    <article
                        v-for="pf in resumen.por_forma"
                        :key="pf.forma"
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <p
                            class="flex items-center gap-2 text-sm font-semibold"
                        >
                            <span
                                :class="
                                    cn(
                                        'size-3 shrink-0 rounded-full',
                                        claseForma(pf.token).fondo,
                                    )
                                "
                            />{{ pf.etiqueta }}
                        </p>
                        <div class="flex items-end gap-3">
                            <span
                                class="text-4xl leading-none font-bold tabular-nums"
                                :class="
                                    pf.bajas === 0 && 'text-muted-foreground/60'
                                "
                                >{{ pf.bajas }}</span
                            >
                            <span class="pb-0.5 text-sm text-muted-foreground"
                                >bajas</span
                            >
                            <span
                                class="ml-auto pb-0.5 text-lg font-semibold tabular-nums"
                                >{{ porcentaje(pf.porcentaje) }}</span
                            >
                        </div>
                        <div
                            class="h-2 overflow-hidden rounded-full bg-muted"
                            aria-hidden="true"
                        >
                            <div
                                :class="
                                    cn(
                                        'h-full rounded-full',
                                        claseForma(pf.token).fondo,
                                    )
                                "
                                :style="{ width: `${pf.porcentaje ?? 0}%` }"
                            />
                        </div>
                        <div
                            class="flex flex-wrap justify-between gap-x-3 text-xs text-muted-foreground"
                        >
                            <span
                                >Índice
                                <strong class="text-foreground tabular-nums">{{
                                    porcentaje(pf.indice)
                                }}</strong></span
                            >
                            <span
                                >Antigüedad
                                <strong class="text-foreground tabular-nums">{{
                                    antiguedadDe(pf.forma) === null
                                        ? '—'
                                        : `${antiguedadDe(pf.forma)} días`
                                }}</strong></span
                            >
                        </div>
                    </article>
                </div>
            </section>
        </template>

        <!-- Tendencia -->
        <section
            v-else-if="vista === 'tendencia'"
            class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
        >
            <div class="flex flex-wrap items-center gap-4 text-xs">
                <span class="flex items-center gap-1.5"
                    ><span class="size-3 rounded-sm bg-primary" />Global (tu
                    alcance)</span
                >
                <span v-if="conSucursal" class="flex items-center gap-1.5"
                    ><span class="size-3 rounded-sm bg-oro" />{{
                        nombreSucursal
                    }}</span
                >
            </div>
            <div class="tabla-scroll">
                <div class="flex h-64 min-w-[42rem] items-end gap-2 pb-1">
                    <div
                        v-for="m in tablero.tendencia"
                        :key="m.periodo"
                        class="flex min-w-12 flex-1 flex-col items-center gap-1"
                    >
                        <span class="text-[11px] font-semibold tabular-nums">{{
                            porcentaje(m.indice)
                        }}</span>
                        <div
                            class="flex h-48 w-full items-end justify-center gap-0.5"
                        >
                            <div
                                class="w-1/2 max-w-7 rounded-t-md bg-primary"
                                :style="{
                                    height: `${((m.indice ?? 0) / maxIndice) * 100}%`,
                                }"
                                :title="`${m.etiqueta}: ${porcentaje(m.indice)} (${m.bajas ?? '—'} bajas, ${fuenteMes(m)})`"
                            />
                            <div
                                v-if="conSucursal"
                                class="w-1/2 max-w-7 rounded-t-md bg-oro"
                                :style="{
                                    height: `${((m.indice_sucursal ?? 0) / maxIndice) * 100}%`,
                                }"
                                :title="`${nombreSucursal} ${m.etiqueta}: ${porcentaje(m.indice_sucursal ?? null)}`"
                            />
                        </div>
                        <span class="text-[11px] text-muted-foreground">{{
                            m.etiqueta
                        }}</span>
                    </div>
                </div>
            </div>
            <div class="tabla-scroll rounded-xl border border-border/60">
                <table class="w-full min-w-[32rem] text-left text-sm">
                    <thead
                        class="bg-[var(--mrl-fondo)] text-xs text-muted-foreground"
                    >
                        <tr>
                            <th class="px-3 py-2 font-medium">Mes</th>
                            <th class="px-3 py-2 font-medium">Bajas</th>
                            <th class="px-3 py-2 font-medium">Plantilla</th>
                            <th class="px-3 py-2 font-medium">Índice</th>
                            <th
                                v-if="conSucursal"
                                class="px-3 py-2 font-medium"
                            >
                                {{ nombreSucursal }}
                            </th>
                            <th class="px-3 py-2 font-medium">Fuente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in tablero.tendencia"
                            :key="m.periodo"
                            class="border-t border-border/50"
                        >
                            <td class="px-3 py-2 font-medium">
                                {{ m.etiqueta }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ m.bajas ?? '—' }}
                            </td>
                            <td class="px-3 py-2 tabular-nums">
                                {{ m.plantilla_promedio ?? '—' }}
                            </td>
                            <td class="px-3 py-2 font-semibold tabular-nums">
                                {{ porcentaje(m.indice) }}
                            </td>
                            <td
                                v-if="conSucursal"
                                class="px-3 py-2 tabular-nums"
                            >
                                {{ porcentaje(m.indice_sucursal ?? null) }}
                            </td>
                            <td class="px-3 py-2 text-xs text-muted-foreground">
                                {{ fuenteMes(m) }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Causales -->
        <section
            v-else-if="vista === 'causales'"
            class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
        >
            <div class="flex flex-wrap gap-x-4 gap-y-2 text-xs">
                <span
                    v-for="fo in tablero.opciones.formas"
                    :key="fo.value"
                    class="flex items-center gap-1.5"
                    ><span
                        :class="
                            cn('size-3 rounded-sm', claseForma(fo.token).fondo)
                        "
                    />{{ fo.label }}</span
                >
            </div>
            <EmptyState
                v-if="!tablero.causales.length"
                :icono="ListTree"
                titulo="Sin bajas cerradas"
                descripcion="No hay bajas del sistema cerradas en el periodo."
            />
            <ul class="flex flex-col gap-3">
                <li
                    v-for="c in tablero.causales"
                    :key="`${c.clave}-${c.nombre}`"
                    class="flex flex-col gap-1.5"
                >
                    <div class="flex items-baseline gap-2">
                        <span
                            v-if="c.clave"
                            class="shrink-0 rounded-md bg-muted px-1.5 font-mono text-[11px] text-muted-foreground"
                            >{{ c.clave }}</span
                        >
                        <span class="min-w-0 flex-1 text-sm font-medium">{{
                            c.nombre
                        }}</span>
                        <span class="shrink-0 text-sm font-bold tabular-nums">{{
                            c.bajas
                        }}</span>
                        <span
                            class="w-14 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                            >{{ porcentaje(c.porcentaje) }}</span
                        >
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-muted">
                        <div
                            :class="
                                cn(
                                    'h-full rounded-full',
                                    claseForma(c.token).fondo,
                                )
                            "
                            :style="{
                                width: `${(c.bajas / maxCausal) * 100}%`,
                                minWidth: '6px',
                            }"
                            :title="`${c.nombre}: ${c.bajas} (${c.porcentaje}%)`"
                        />
                    </div>
                </li>
            </ul>
        </section>

        <!-- Sucursales -->
        <template v-else-if="vista === 'sucursales'">
            <EmptyState
                v-if="!tablero.sucursales.length"
                :icono="Building2"
                titulo="Sin datos"
                descripcion="No hay rotación registrada en el periodo."
            />
            <template v-else>
                <!-- Móvil: tarjetas -->
                <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:hidden">
                    <li
                        v-for="s in sucursalesConBajas"
                        :key="s.sucursal_id"
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <div class="flex items-start gap-2">
                            <p class="min-w-0 flex-1 font-semibold">
                                {{ s.sucursal }}
                            </p>
                            <span
                                v-if="s.tendencia === 'sube'"
                                class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-2 py-0.5 text-xs font-medium text-semaforo-sube"
                                ><ArrowUpRight class="size-3.5" />Sube</span
                            >
                            <span
                                v-else-if="s.tendencia === 'baja'"
                                class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 text-xs font-medium text-semaforo-baja"
                                ><ArrowDownRight class="size-3.5" />Baja</span
                            >
                        </div>
                        <div class="flex items-end gap-4">
                            <div>
                                <p
                                    class="text-3xl leading-none font-bold tabular-nums"
                                >
                                    {{ porcentaje(s.indice) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    índice
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-2xl leading-none font-semibold tabular-nums"
                                >
                                    {{ s.bajas }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    bajas
                                </p>
                            </div>
                        </div>
                        <dl class="grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-lg bg-[var(--mrl-fondo)] p-2">
                                <dt class="text-muted-foreground">RV/NR/RE</dt>
                                <dd class="font-medium tabular-nums">
                                    {{ porcentaje(s.por_forma.RV) }} ·
                                    {{ porcentaje(s.por_forma.NR) }} ·
                                    {{ porcentaje(s.por_forma.RE) }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-[var(--mrl-fondo)] p-2">
                                <dt class="text-muted-foreground">Temprana</dt>
                                <dd class="font-medium tabular-nums">
                                    {{ porcentaje(s.rotacion_temprana) }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-[var(--mrl-fondo)] p-2">
                                <dt class="text-muted-foreground">
                                    Expediente
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{ porcentaje(s.calidad_expediente) }}
                                </dd>
                            </div>
                        </dl>
                        <p
                            v-if="s.causal_principal"
                            class="truncate text-xs text-muted-foreground"
                            :title="s.causal_principal"
                        >
                            Causal principal:
                            <span class="text-foreground">{{
                                s.causal_principal
                            }}</span>
                        </p>
                    </li>
                </ul>
                <div
                    v-if="sucursalesSinBajas.length"
                    class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-4 lg:hidden"
                >
                    <p class="text-sm font-semibold text-muted-foreground">
                        Sin bajas en el periodo ({{
                            sucursalesSinBajas.length
                        }})
                    </p>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="s in sucursalesSinBajas"
                            :key="s.sucursal_id"
                            class="rounded-full bg-success-soft px-2.5 py-0.5 text-xs text-success"
                        >
                            {{ s.sucursal }}
                        </li>
                    </ul>
                </div>

                <!-- Escritorio: tabla -->
                <div
                    class="hidden tabla-scroll rounded-2xl border border-border/60 bg-card shadow-sm lg:block"
                >
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-[var(--mrl-fondo)] text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Sucursal</th>
                                <th class="px-4 py-3 font-medium">Bajas</th>
                                <th class="px-4 py-3 font-medium">Índice</th>
                                <th class="px-4 py-3 font-medium">
                                    Vs. mes anterior
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    RV / NR / RE
                                </th>
                                <th class="px-4 py-3 font-medium">
                                    Causal principal
                                </th>
                                <th class="px-4 py-3 font-medium">Temprana</th>
                                <th class="px-4 py-3 font-medium">
                                    Expediente
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="s in tablero.sucursales"
                                :key="s.sucursal_id"
                                class="border-t border-border/50"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ s.sucursal }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ s.bajas }}
                                </td>
                                <td
                                    class="px-4 py-3 text-base font-bold tabular-nums"
                                >
                                    {{ porcentaje(s.indice) }}
                                </td>
                                <td class="px-4 py-3 text-xs">
                                    <span
                                        v-if="s.tendencia === 'sube'"
                                        class="inline-flex items-center gap-1 rounded-full bg-danger-soft px-2 py-0.5 font-medium text-semaforo-sube"
                                        ><ArrowUpRight class="size-3.5" />Sube
                                        {{ porcentaje(s.indice_mes) }}</span
                                    >
                                    <span
                                        v-else-if="s.tendencia === 'baja'"
                                        class="inline-flex items-center gap-1 rounded-full bg-success-soft px-2 py-0.5 font-medium text-semaforo-baja"
                                        ><ArrowDownRight class="size-3.5" />Baja
                                        {{ porcentaje(s.indice_mes) }}</span
                                    >
                                    <span
                                        v-else
                                        class="inline-flex items-center gap-1 text-muted-foreground"
                                        ><Minus class="size-4" />{{
                                            s.tendencia === 'igual'
                                                ? 'Igual'
                                                : '—'
                                        }}</span
                                    >
                                </td>
                                <td class="px-4 py-3 text-xs tabular-nums">
                                    {{ porcentaje(s.por_forma.RV) }} /
                                    {{ porcentaje(s.por_forma.NR) }} /
                                    {{ porcentaje(s.por_forma.RE) }}
                                </td>
                                <td class="max-w-64 px-4 py-3 text-xs">
                                    {{ s.causal_principal ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs tabular-nums">
                                    {{ porcentaje(s.rotacion_temprana) }}
                                </td>
                                <td class="px-4 py-3 text-xs tabular-nums">
                                    {{ porcentaje(s.calidad_expediente) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>

        <!-- Puestos -->
        <template v-else>
            <EmptyState
                v-if="!tablero.puestos.length"
                :icono="Users"
                titulo="Sin bajas cerradas"
                descripcion="No hay bajas del sistema cerradas en el periodo."
            />
            <template v-else>
                <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:hidden">
                    <li
                        v-for="p in tablero.puestos"
                        :key="p.puesto"
                        class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-card p-4 shadow-sm"
                    >
                        <p class="font-semibold">{{ p.puesto }}</p>
                        <div class="flex items-end gap-4">
                            <div>
                                <p
                                    class="text-3xl leading-none font-bold tabular-nums"
                                >
                                    {{ porcentaje(p.indice) }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    índice
                                </p>
                            </div>
                            <div>
                                <p
                                    class="text-2xl leading-none font-semibold tabular-nums"
                                >
                                    {{ p.bajas }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    bajas
                                </p>
                            </div>
                        </div>
                        <dl class="grid grid-cols-3 gap-2 text-xs">
                            <div class="rounded-lg bg-[var(--mrl-fondo)] p-2">
                                <dt class="text-muted-foreground">Plantilla</dt>
                                <dd class="font-medium tabular-nums">
                                    {{ p.plantilla_promedio ?? '—' }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-[var(--mrl-fondo)] p-2">
                                <dt class="text-muted-foreground">Temprana</dt>
                                <dd class="font-medium tabular-nums">
                                    {{ porcentaje(p.rotacion_temprana) }}
                                </dd>
                            </div>
                            <div class="rounded-lg bg-[var(--mrl-fondo)] p-2">
                                <dt class="text-muted-foreground">
                                    Antigüedad
                                </dt>
                                <dd class="font-medium tabular-nums">
                                    {{ p.antiguedad_promedio_dias ?? '—' }} d
                                </dd>
                            </div>
                        </dl>
                    </li>
                </ul>
                <div
                    class="hidden tabla-scroll rounded-2xl border border-border/60 bg-card shadow-sm lg:block"
                >
                    <table class="w-full text-left text-sm">
                        <thead
                            class="bg-[var(--mrl-fondo)] text-xs text-muted-foreground"
                        >
                            <tr>
                                <th class="px-4 py-3 font-medium">Puesto</th>
                                <th class="px-4 py-3 font-medium">Bajas</th>
                                <th class="px-4 py-3 font-medium">
                                    Plantilla prom.
                                </th>
                                <th class="px-4 py-3 font-medium">Índice</th>
                                <th class="px-4 py-3 font-medium">Temprana</th>
                                <th class="px-4 py-3 font-medium">
                                    Antigüedad prom.
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="p in tablero.puestos"
                                :key="p.puesto"
                                class="border-t border-border/50"
                            >
                                <td class="px-4 py-3 font-medium">
                                    {{ p.puesto }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ p.bajas }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ p.plantilla_promedio ?? '—' }}
                                </td>
                                <td
                                    class="px-4 py-3 text-base font-bold tabular-nums"
                                >
                                    {{ porcentaje(p.indice) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ porcentaje(p.rotacion_temprana) }}
                                </td>
                                <td class="px-4 py-3 tabular-nums">
                                    {{ p.antiguedad_promedio_dias ?? '—' }}
                                    días
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </template>
    </div>
</template>
