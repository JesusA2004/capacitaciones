<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowDownRight,
    ArrowUpRight,
    BarChart3,
    FileSpreadsheet,
    FileText,
    History,
    Minus,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import MonthPicker from '@/components/Common/MonthPicker.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { claseForma, porcentaje } from '@/lib/bajas';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as rotacionHistorica } from '@/routes/reportes/rotacion';
import rutasTablero from '@/routes/reportes/rotacion/tablero';
import type { MesRotacion, TableroRotacion } from '@/types';

/**
 * Tablero de rotación (especificación de RH §9). Combina dos fuentes:
 * los meses anteriores al módulo salen del Excel de rotación ya importado
 * (agregados por sucursal) y, desde el mes de inicio del sistema, de las
 * bajas cerradas del módulo (es_rotacion) con la plantilla mensual. El
 * desglose por forma, causal y puesto solo existe para bajas del sistema.
 * Colores por forma: tokens --forma-salida-* (resources/css/app.css).
 */
const props = defineProps<{
    tablero: TableroRotacion;
    puedeCargarExcel: boolean;
    puedeVerHistorico: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Rotación de personal', href: '' },
        ],
    },
});

type Vista = 'resumen' | 'tendencia' | 'causales' | 'sucursales' | 'puestos';
const vista = ref<Vista>('resumen');
const vistas: { valor: Vista; etiqueta: string }[] = [
    { valor: 'resumen', etiqueta: 'Resumen' },
    { valor: 'tendencia', etiqueta: 'Tendencia 12 meses' },
    { valor: 'causales', etiqueta: 'Causales' },
    { valor: 'sucursales', etiqueta: 'Sucursales' },
    { valor: 'puestos', etiqueta: 'Puestos' },
];

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

const claseTab = (activo: boolean) =>
    cn(
        '-mb-px inline-flex h-10 shrink-0 items-center border-b-2 px-3 text-sm font-medium transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring/50',
        activo
            ? 'border-primary text-foreground'
            : 'border-transparent text-muted-foreground hover:text-foreground',
    );
</script>

<template>
    <Head title="Rotación de personal" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Rotación de personal"
            :descripcion="`Bajas ÷ plantilla promedio × 100. Hasta ${tablero.mes_inicio_sistema} se usa el Excel de rotación; desde ese mes, las bajas cerradas del sistema.`"
            :icono="BarChart3"
        >
            <Button variant="outline" as-child>
                <a :href="urlExcel"><FileSpreadsheet class="size-4" />Excel</a>
            </Button>
            <Button variant="outline" as-child>
                <a :href="urlPdf"><FileText class="size-4" />PDF</a>
            </Button>
            <Button v-if="puedeVerHistorico" variant="ghost" as-child>
                <Link :href="rotacionHistorica()"
                    ><History class="size-4" />{{
                        puedeCargarExcel
                            ? 'Histórico (cargar Excel)'
                            : 'Histórico del Excel'
                    }}</Link
                >
            </Button>
        </CrudPageHeader>

        <div class="grid gap-2 sm:grid-cols-3 xl:grid-cols-6">
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Desde</Label
                >
                <MonthPicker v-model="desde" aria-label="Desde" />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Hasta</Label
                >
                <MonthPicker v-model="hasta" aria-label="Hasta" />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
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
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
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
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
                    >Forma de salida</Label
                >
                <SelectSimple
                    v-model="forma"
                    :opciones="tablero.opciones.formas"
                    opcion-vacia="Todas"
                    placeholder="Todas"
                />
            </div>
            <div>
                <Label class="mb-1.5 text-xs text-[var(--mrl-texto-suave)]"
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

        <nav
            class="scroll-x-limpio flex max-w-full border-b"
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
                {{ v.etiqueta }}
            </button>
        </nav>

        <!-- Resumen -->
        <template v-if="vista === 'resumen'">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Índice del mes · {{ resumen.mes.etiqueta }}
                    </p>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ porcentaje(resumen.mes.indice) }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ resumen.mes.bajas ?? '—' }} bajas · plantilla
                        {{ resumen.mes.plantilla_promedio ?? '—' }} ·
                        {{ fuenteMes(resumen.mes) }}
                    </p>
                </div>
                <div
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Acumulado {{ resumen.acumulado.anio }}
                    </p>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ porcentaje(resumen.acumulado.indice) }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ resumen.acumulado.bajas }} bajas · plantilla promedio
                        {{ resumen.acumulado.plantilla_promedio ?? '—' }}
                    </p>
                </div>
                <div
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Rotación temprana (menos de 90 días)
                    </p>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ porcentaje(resumen.rotacion_temprana.porcentaje) }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ resumen.rotacion_temprana.bajas }} de
                        {{ resumen.bajas_sistema }} bajas del sistema
                    </p>
                </div>
                <div
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Calidad del expediente
                    </p>
                    <p class="text-3xl font-semibold tabular-nums">
                        {{ porcentaje(resumen.calidad_expediente.porcentaje) }}
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        {{ resumen.calidad_expediente.completas }} de
                        {{ resumen.calidad_expediente.cerradas }} cerradas con
                        todos sus documentos
                    </p>
                </div>
            </div>

            <div class="grid gap-3 lg:grid-cols-3">
                <div
                    v-for="pf in resumen.por_forma"
                    :key="pf.forma"
                    class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
                >
                    <p class="flex items-center gap-2 text-sm font-medium">
                        <span
                            :class="
                                cn(
                                    'size-3 rounded-full',
                                    claseForma(pf.token).fondo,
                                )
                            "
                        />{{ pf.etiqueta }}
                    </p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums">
                        {{ pf.bajas }}
                        <span
                            class="text-sm font-normal text-[var(--mrl-texto-suave)]"
                            >bajas · {{ porcentaje(pf.porcentaje) }} del total ·
                            índice {{ porcentaje(pf.indice) }}</span
                        >
                    </p>
                    <p class="text-xs text-[var(--mrl-texto-suave)]">
                        Antigüedad promedio al salir:
                        {{
                            resumen.antiguedad_por_forma.find(
                                (a) => a.forma === pf.forma,
                            )?.dias ?? '—'
                        }}
                        días
                    </p>
                </div>
            </div>
            <p class="text-xs text-[var(--mrl-texto-suave)]">
                Antigüedad promedio al salir:
                {{ resumen.antiguedad_promedio_dias ?? '—' }} días. El desglose
                por forma, causal, puesto, antigüedad y calidad del expediente
                solo existe para las bajas del sistema (desde
                {{ tablero.mes_inicio_sistema }}); el Excel histórico trae solo
                totales por sucursal.
            </p>
        </template>

        <!-- Tendencia -->
        <section
            v-else-if="vista === 'tendencia'"
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <div class="mb-4 flex flex-wrap items-center gap-4 text-xs">
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
            <div class="flex h-64 items-end gap-2 overflow-x-auto pb-1">
                <div
                    v-for="m in tablero.tendencia"
                    :key="m.periodo"
                    class="flex min-w-14 flex-1 flex-col items-center gap-1"
                >
                    <div
                        class="flex h-52 w-full items-end justify-center gap-0.5"
                    >
                        <div
                            class="group relative w-1/2 max-w-6 rounded-t bg-primary"
                            :style="{
                                height: `${((m.indice ?? 0) / maxIndice) * 100}%`,
                            }"
                            :title="`${m.etiqueta}: ${porcentaje(m.indice)} (${m.bajas ?? '—'} bajas, ${fuenteMes(m)})`"
                        />
                        <div
                            v-if="conSucursal"
                            class="w-1/2 max-w-6 rounded-t bg-oro"
                            :style="{
                                height: `${((m.indice_sucursal ?? 0) / maxIndice) * 100}%`,
                            }"
                            :title="`${nombreSucursal} ${m.etiqueta}: ${porcentaje(m.indice_sucursal ?? null)}`"
                        />
                    </div>
                    <span class="text-[11px] tabular-nums">{{
                        porcentaje(m.indice)
                    }}</span>
                    <span class="text-[11px] text-[var(--mrl-texto-suave)]">{{
                        m.etiqueta
                    }}</span>
                </div>
            </div>
            <div class="mt-4 tabla-scroll">
                <table class="w-full text-left text-xs">
                    <thead class="text-[var(--mrl-texto-suave)]">
                        <tr>
                            <th class="py-1 pr-3 font-medium">Mes</th>
                            <th class="py-1 pr-3 font-medium">Bajas</th>
                            <th class="py-1 pr-3 font-medium">
                                Plantilla prom.
                            </th>
                            <th class="py-1 pr-3 font-medium">Índice</th>
                            <th
                                v-if="conSucursal"
                                class="py-1 pr-3 font-medium"
                            >
                                Índice {{ nombreSucursal }}
                            </th>
                            <th class="py-1 pr-3 font-medium">Fuente</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="m in tablero.tendencia"
                            :key="m.periodo"
                            class="border-t border-[var(--mrl-borde)]"
                        >
                            <td class="py-1 pr-3">{{ m.etiqueta }}</td>
                            <td class="py-1 pr-3 tabular-nums">
                                {{ m.bajas ?? '—' }}
                            </td>
                            <td class="py-1 pr-3 tabular-nums">
                                {{ m.plantilla_promedio ?? '—' }}
                            </td>
                            <td class="py-1 pr-3 tabular-nums">
                                {{ porcentaje(m.indice) }}
                            </td>
                            <td
                                v-if="conSucursal"
                                class="py-1 pr-3 tabular-nums"
                            >
                                {{ porcentaje(m.indice_sucursal ?? null) }}
                            </td>
                            <td class="py-1 pr-3 text-[var(--mrl-texto-suave)]">
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
            class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-5"
        >
            <div class="mb-4 flex flex-wrap gap-4 text-xs">
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
            <p
                v-if="!tablero.causales.length"
                class="py-6 text-center text-sm text-[var(--mrl-texto-suave)]"
            >
                No hay bajas del sistema cerradas en el periodo.
            </p>
            <ul class="grid gap-2">
                <li
                    v-for="c in tablero.causales"
                    :key="`${c.clave}-${c.nombre}`"
                    class="grid gap-1 sm:grid-cols-[minmax(12rem,22rem)_1fr] sm:items-center sm:gap-3"
                >
                    <span class="text-sm"
                        ><span
                            v-if="c.clave"
                            class="font-mono text-xs text-[var(--mrl-texto-suave)]"
                            >{{ c.clave }}</span
                        >
                        {{ c.nombre }}</span
                    >
                    <span class="flex items-center gap-2">
                        <span
                            :class="
                                cn('h-4 rounded-r', claseForma(c.token).fondo)
                            "
                            :style="{
                                width: `${(c.bajas / maxCausal) * 100}%`,
                                minWidth: '4px',
                            }"
                            :title="`${c.nombre}: ${c.bajas} (${c.porcentaje}%)`"
                        />
                        <span class="shrink-0 text-xs tabular-nums"
                            >{{ c.bajas }} ·
                            {{ porcentaje(c.porcentaje) }}</span
                        >
                    </span>
                </li>
            </ul>
        </section>

        <!-- Sucursales -->
        <section
            v-else-if="vista === 'sucursales'"
            class="tabla-scroll rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-[var(--mrl-borde)] text-xs text-[var(--mrl-texto-suave)]"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Sucursal</th>
                        <th class="px-4 py-3 font-medium">Bajas</th>
                        <th class="px-4 py-3 font-medium">Índice</th>
                        <th class="px-4 py-3 font-medium">Vs. mes anterior</th>
                        <th class="px-4 py-3 font-medium">RV / NR / RE</th>
                        <th class="px-4 py-3 font-medium">Causal principal</th>
                        <th class="px-4 py-3 font-medium">Temprana</th>
                        <th class="px-4 py-3 font-medium">Expediente</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!tablero.sucursales.length">
                        <td
                            colspan="8"
                            class="px-4 py-6 text-center text-[var(--mrl-texto-suave)]"
                        >
                            Sin datos en el periodo.
                        </td>
                    </tr>
                    <tr
                        v-for="s in tablero.sucursales"
                        :key="s.sucursal_id"
                        class="border-b border-[var(--mrl-borde)] last:border-0"
                    >
                        <td class="px-4 py-3 font-medium">{{ s.sucursal }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ s.bajas }}</td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ porcentaje(s.indice) }}
                        </td>
                        <td class="px-4 py-3 text-xs">
                            <span
                                v-if="s.tendencia === 'sube'"
                                class="inline-flex items-center gap-1 font-medium text-semaforo-sube"
                                ><ArrowUpRight class="size-4" />Sube
                                {{ porcentaje(s.indice_mes) }}</span
                            >
                            <span
                                v-else-if="s.tendencia === 'baja'"
                                class="inline-flex items-center gap-1 font-medium text-semaforo-baja"
                                ><ArrowDownRight class="size-4" />Baja
                                {{ porcentaje(s.indice_mes) }}</span
                            >
                            <span
                                v-else
                                class="inline-flex items-center gap-1 text-[var(--mrl-texto-suave)]"
                                ><Minus class="size-4" />{{
                                    s.tendencia === 'igual' ? 'Igual' : '—'
                                }}</span
                            >
                        </td>
                        <td class="px-4 py-3 text-xs tabular-nums">
                            {{ porcentaje(s.por_forma.RV) }} /
                            {{ porcentaje(s.por_forma.NR) }} /
                            {{ porcentaje(s.por_forma.RE) }}
                        </td>
                        <td class="px-4 py-3 text-xs">
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
        </section>

        <!-- Puestos -->
        <section
            v-else
            class="tabla-scroll rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
        >
            <table class="w-full text-left text-sm">
                <thead
                    class="border-b border-[var(--mrl-borde)] text-xs text-[var(--mrl-texto-suave)]"
                >
                    <tr>
                        <th class="px-4 py-3 font-medium">Puesto</th>
                        <th class="px-4 py-3 font-medium">Bajas</th>
                        <th class="px-4 py-3 font-medium">Plantilla prom.</th>
                        <th class="px-4 py-3 font-medium">Índice</th>
                        <th class="px-4 py-3 font-medium">Temprana</th>
                        <th class="px-4 py-3 font-medium">Antigüedad prom.</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!tablero.puestos.length">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-[var(--mrl-texto-suave)]"
                        >
                            No hay bajas del sistema cerradas en el periodo.
                        </td>
                    </tr>
                    <tr
                        v-for="p in tablero.puestos"
                        :key="p.puesto"
                        class="border-b border-[var(--mrl-borde)] last:border-0"
                    >
                        <td class="px-4 py-3 font-medium">{{ p.puesto }}</td>
                        <td class="px-4 py-3 tabular-nums">{{ p.bajas }}</td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ p.plantilla_promedio ?? '—' }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ porcentaje(p.indice) }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ porcentaje(p.rotacion_temprana) }}
                        </td>
                        <td class="px-4 py-3 tabular-nums">
                            {{ p.antiguedad_promedio_dias ?? '—' }} días
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
