<script setup lang="ts">
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    History,
    Lock,
    Search,
    Tags,
    Upload,
    X,
} from '@lucide/vue';
import { useDebounceFn, useMediaQuery } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import AccionesFilaRotacion from '@/components/rotacion/AccionesFilaRotacion.vue';
import CalidadDatoRotacion from '@/components/rotacion/CalidadDatoRotacion.vue';
import CargaHistoricoSheet from '@/components/rotacion/CargaHistoricoSheet.vue';
import DialogosCorreccionRotacion from '@/components/rotacion/DialogosCorreccionRotacion.vue';
import type { ModoCorreccionRotacion } from '@/components/rotacion/DialogosCorreccionRotacion.vue';
import HomologacionSheet from '@/components/rotacion/HomologacionSheet.vue';
import { Button } from '@/components/ui/button';
import ListaAnimada from '@/components/ui/ListaAnimada.vue';
import type { PorPagina } from '@/composables/usePaginacion';
import { PARAMETRO_POR_PAGINA } from '@/composables/usePaginacion';
import { formatearFecha } from '@/lib/fechas';
import {
    ALERTAS_HISTORICO,
    etiquetaMes,
    FILTROS_RAPIDOS,
    numero,
    tonoClasificacion,
    useNavegacionRotacion,
} from '@/lib/rotacion';
import { cn } from '@/lib/utils';
import type {
    CalidadRotacion,
    CatalogoHomologacionRotacion,
    DesgloseRotacion,
    FilaHistoricoRotacion,
    ImportacionHistoricoRotacion,
    OpcionesRotacion,
    PaginaHistoricoRotacion,
    PermisosRotacion,
    PosibleDuplicadoRotacion,
} from '@/types';

/**
 * Histórico de bajas (sistema + históricas importadas) paginado en el
 * servidor. Búsqueda, filtros rápidos y el desglose con el que se llegó
 * desde una gráfica; cada cambio recarga solo el histórico.
 */
const props = defineProps<{
    historico: PaginaHistoricoRotacion | null;
    desglose: DesgloseRotacion;
    opciones: OpcionesRotacion;
    puede: PermisosRotacion;
    calidad: CalidadRotacion;
    conciliacion: PosibleDuplicadoRotacion[] | undefined;
    catalogo: CatalogoHomologacionRotacion | undefined;
    importacion: ImportacionHistoricoRotacion | null;
}>();

const nav = useNavegacionRotacion();
const escritorio = useMediaQuery('(min-width: 1024px)');

/* --- Búsqueda ----------------------------------------------------------- */

const busqueda = ref(props.desglose.q ?? '');
const buscar = useDebounceFn((q: string) => {
    if ((props.desglose.q ?? '') !== q.trim()) {
        nav.actualizar({ q: q.trim() || null });
    }
}, 350);
watch(busqueda, (q) => buscar(q));
watch(
    () => props.desglose.q,
    (q) => {
        if ((q ?? '') !== busqueda.value.trim()) {
            busqueda.value = q ?? '';
        }
    },
);

/* --- Desglose activo ---------------------------------------------------- */

const chipsDesglose = computed(() => {
    const d = props.desglose;
    const chips: { clave: string; etiqueta: string }[] = [];

    if (d.d_mes) {
        chips.push({
            clave: 'd_mes',
            etiqueta: `Mes: ${etiquetaMes(d.d_mes)}`,
        });
    }

    if (d.d_sucursal) {
        const s = props.opciones.sucursales.find(
            (x) => String(x.id) === d.d_sucursal,
        );
        chips.push({
            clave: 'd_sucursal',
            etiqueta: `Sucursal: ${s?.nombre ?? `#${d.d_sucursal}`}`,
        });
    }

    if (d.d_causal) {
        const c = props.opciones.causales.find(
            (x) => String(x.id) === d.d_causal,
        );
        chips.push({
            clave: 'd_causal',
            etiqueta: `Causal: ${d.d_causal === 'pendiente' ? 'Sin homologar' : (c?.nombre ?? `#${d.d_causal}`)}`,
        });
    }

    if (d.d_rango) {
        const r = props.opciones.antiguedades.find(
            (x) => x.value === d.d_rango,
        );
        chips.push({
            clave: 'd_rango',
            etiqueta: `Antigüedad: ${r?.label ?? d.d_rango}`,
        });
    }

    if (d.d_clasificacion) {
        const c = props.opciones.clasificaciones.find(
            (x) => x.value === d.d_clasificacion,
        );
        chips.push({
            clave: 'd_clasificacion',
            etiqueta: `Tipo: ${c?.label ?? d.d_clasificacion}`,
        });
    }

    return chips;
});

function quitarDesglose(clave: string): void {
    nav.actualizar({ [clave]: null });
}

function quitarTodo(): void {
    busqueda.value = '';
    nav.actualizar({
        d_mes: null,
        d_sucursal: null,
        d_causal: null,
        d_rango: null,
        d_clasificacion: null,
        rapido: null,
        q: null,
    });
}

function alternarRapido(valor: string): void {
    nav.actualizar({ rapido: props.desglose.rapido === valor ? null : valor });
}

/* --- Orden ------------------------------------------------------------- */

type ColumnaOrden =
    'fecha_baja' | 'nombre' | 'sucursal' | 'antiguedad' | 'causal';

const orden = computed<ColumnaOrden>(() => {
    const o = props.desglose.orden;

    return o === 'nombre' ||
        o === 'sucursal' ||
        o === 'antiguedad' ||
        o === 'causal'
        ? o
        : 'fecha_baja';
});
const dir = computed<'asc' | 'desc'>(() =>
    props.desglose.dir === 'asc' || props.desglose.dir === 'desc'
        ? props.desglose.dir
        : orden.value === 'fecha_baja'
          ? 'desc'
          : 'asc',
);

function ordenar(col: ColumnaOrden): void {
    const nuevaDir =
        orden.value === col
            ? dir.value === 'asc'
                ? 'desc'
                : 'asc'
            : col === 'fecha_baja'
              ? 'desc'
              : 'asc';

    nav.actualizar({ orden: col, dir: nuevaDir });
}

function ariaOrden(col: ColumnaOrden): 'ascending' | 'descending' | 'none' {
    return orden.value === col
        ? dir.value === 'asc'
            ? 'ascending'
            : 'descending'
        : 'none';
}

const COLUMNAS_ORDEN: { col: ColumnaOrden; etiqueta: string }[] = [
    { col: 'fecha_baja', etiqueta: 'Fecha de baja' },
    { col: 'nombre', etiqueta: 'Nombre' },
    { col: 'sucursal', etiqueta: 'Sucursal' },
    { col: 'antiguedad', etiqueta: 'Antigüedad' },
    { col: 'causal', etiqueta: 'Causal' },
];

/* --- Paginación --------------------------------------------------------- */

function irAPagina(pagina: number): void {
    nav.actualizar({ page: pagina });
}

function cambiarPorPagina(valor: PorPagina): void {
    nav.actualizar({ [PARAMETRO_POR_PAGINA]: String(valor), page: null });
}

/* --- Correcciones ------------------------------------------------------- */

const filaActiva = ref<FilaHistoricoRotacion | null>(null);
const modo = ref<ModoCorreccionRotacion | null>(null);

function abrirCorreccion(
    fila: FilaHistoricoRotacion,
    m: ModoCorreccionRotacion,
): void {
    filaActiva.value = fila;
    modo.value = m;
}

function cerrarCorreccion(): void {
    filaActiva.value = null;
    modo.value = null;
}

function antiguedadCorta(f: FilaHistoricoRotacion): string {
    if (f.antiguedad_dias === null) {
        return '—';
    }

    const d = f.antiguedad_dias;

    if (d < 60) {
        return `${d} días`;
    }

    if (d < 730) {
        return `${Math.floor(d / 30.44)} meses`;
    }

    return `${(d / 365.25).toFixed(1)} años`;
}

/* --- Hojas -------------------------------------------------------------- */

const cargaAbierta = ref(false);
const homologacionAbierta = ref(false);

const hayDesglose = computed(
    () =>
        chipsDesglose.value.length > 0 ||
        props.desglose.rapido !== null ||
        (props.desglose.q ?? '') !== '',
);
</script>

<template>
    <div class="flex flex-col gap-5">
        <EmptyState
            v-if="!puede.ver_personas || historico === null"
            :icono="Lock"
            titulo="Necesitas permiso para ver personas"
            descripcion="El histórico muestra nombres y expedientes. Pide a Sistemas el permiso de ver o gestionar bajas; mientras, las demás secciones siguen disponibles."
        />

        <section
            v-else
            class="flex min-w-0 flex-col gap-3 rounded-3xl border border-border/60 bg-card p-4 shadow-sm"
            aria-labelledby="titulo-historico"
        >
            <div class="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2
                        id="titulo-historico"
                        class="flex items-center gap-2 font-semibold"
                    >
                        <History
                            class="size-4 text-bronce"
                            aria-hidden="true"
                        />
                        Histórico de bajas
                    </h2>
                    <p class="text-xs text-muted-foreground tabular-nums">
                        {{ numero(historico.total) }}
                        {{ historico.total === 1 ? 'baja' : 'bajas' }} con estos
                        filtros
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="puede.corregir"
                        variant="outline"
                        size="sm"
                        @click="homologacionAbierta = true"
                    >
                        <Tags class="size-4" />Homologación de causales
                    </Button>
                    <Button
                        v-if="puede.cargar_historico"
                        variant="outline"
                        size="sm"
                        @click="cargaAbierta = true"
                    >
                        <Upload class="size-4" />Cargar histórico
                    </Button>
                </div>
            </div>

            <label class="relative block">
                <Search
                    class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-muted-foreground"
                />
                <span class="sr-only"
                    >Buscar por nombre o número de empleado</span
                >
                <input
                    v-model="busqueda"
                    type="search"
                    placeholder="Buscar por nombre o número de empleado…"
                    class="h-11 w-full rounded-2xl border bg-background/60 pr-10 pl-10 text-sm transition-[border-color,box-shadow] outline-none placeholder:text-muted-foreground focus:border-primary/50 focus:ring-4 focus:ring-primary/10"
                />
                <button
                    v-if="busqueda"
                    type="button"
                    class="absolute top-1/2 right-2 grid size-7 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-crema/60"
                    aria-label="Limpiar búsqueda"
                    @click="busqueda = ''"
                >
                    <X class="size-3.5" />
                </button>
            </label>

            <div
                class="-mx-1 scroll-x-limpio flex gap-1.5 px-1 pb-0.5"
                role="group"
                aria-label="Filtros rápidos"
            >
                <button
                    v-for="r in FILTROS_RAPIDOS"
                    :key="r.valor"
                    type="button"
                    :class="
                        cn(
                            'h-8 shrink-0 rounded-full px-3 text-xs font-medium transicion-ui',
                            desglose.rapido === r.valor
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'bg-crema/50 text-muted-foreground hover:text-foreground',
                        )
                    "
                    :aria-pressed="desglose.rapido === r.valor"
                    @click="alternarRapido(r.valor)"
                >
                    {{ r.etiqueta }}
                </button>
            </div>

            <div
                v-if="hayDesglose"
                class="flex flex-wrap items-center gap-1.5"
                aria-label="Desglose activo"
            >
                <span class="text-xs text-muted-foreground">Mostrando:</span>
                <button
                    v-for="c in chipsDesglose"
                    :key="c.clave"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-full bg-salvia px-2.5 py-1 text-xs text-primary transition-colors hover:bg-salvia/70"
                    :aria-label="`Quitar ${c.etiqueta}`"
                    @click="quitarDesglose(c.clave)"
                >
                    {{ c.etiqueta }}
                    <X class="size-3" />
                </button>
                <button
                    type="button"
                    class="enlace-animado text-xs text-muted-foreground hover:text-foreground"
                    @click="quitarTodo"
                >
                    Quitar todo
                </button>
            </div>

            <EmptyState
                v-if="!historico.data.length"
                :icono="History"
                titulo="Sin bajas con estos filtros"
                descripcion="Quita un filtro o el desglose para ver más registros."
            />

            <!-- Escritorio -->
            <div
                v-else-if="escritorio"
                class="tabla-scroll rounded-2xl border border-border/60"
                :class="nav.cargando.value && 'tabla-actualizando'"
            >
                <table class="w-full min-w-[78rem] text-left text-xs">
                    <thead class="bg-crema/50 text-muted-foreground">
                        <tr>
                            <th
                                v-for="c in COLUMNAS_ORDEN.slice(0, 2)"
                                :key="c.col"
                                class="px-2.5 py-2 font-medium"
                                :aria-sort="ariaOrden(c.col)"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    @click="ordenar(c.col)"
                                >
                                    {{ c.etiqueta }}
                                    <ArrowDown
                                        v-if="orden === c.col && dir === 'desc'"
                                        class="size-3"
                                    />
                                    <ArrowUp
                                        v-else-if="orden === c.col"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th
                                class="px-2.5 py-2 font-medium"
                                :aria-sort="ariaOrden('sucursal')"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    @click="ordenar('sucursal')"
                                >
                                    Empresa / Sucursal
                                    <ArrowDown
                                        v-if="
                                            orden === 'sucursal' &&
                                            dir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUp
                                        v-else-if="orden === 'sucursal'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-2.5 py-2 font-medium">Puesto</th>
                            <th class="px-2.5 py-2 font-medium">
                                Fecha de ingreso
                            </th>
                            <th
                                class="px-2.5 py-2 font-medium"
                                :aria-sort="ariaOrden('antiguedad')"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    @click="ordenar('antiguedad')"
                                >
                                    Antigüedad
                                    <ArrowDown
                                        v-if="
                                            orden === 'antiguedad' &&
                                            dir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUp
                                        v-else-if="orden === 'antiguedad'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-2.5 py-2 font-medium">
                                Motivo original
                            </th>
                            <th
                                class="px-2.5 py-2 font-medium"
                                :aria-sort="ariaOrden('causal')"
                            >
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 hover:text-foreground"
                                    @click="ordenar('causal')"
                                >
                                    Causal homologada
                                    <ArrowDown
                                        v-if="
                                            orden === 'causal' && dir === 'desc'
                                        "
                                        class="size-3"
                                    />
                                    <ArrowUp
                                        v-else-if="orden === 'causal'"
                                        class="size-3"
                                    />
                                    <ArrowUpDown
                                        v-else
                                        class="size-3 opacity-40"
                                    />
                                </button>
                            </th>
                            <th class="px-2.5 py-2 font-medium">Tipo</th>
                            <th class="px-2.5 py-2 font-medium">Estado</th>
                            <th class="px-2.5 py-2 font-medium">Origen</th>
                            <th class="px-2.5 py-2 font-medium">Alertas</th>
                            <th class="px-2.5 py-2 font-medium">
                                <span class="sr-only">Acciones</span>
                            </th>
                        </tr>
                    </thead>
                    <ListaAnimada tag="tbody" :cantidad="historico.data.length">
                        <tr
                            v-for="f in historico.data"
                            :key="f.clave"
                            class="border-t border-border/40 align-top transition-colors hover:bg-crema/30"
                        >
                            <td class="px-2.5 py-2 whitespace-nowrap">
                                {{ formatearFecha(f.fecha_baja) }}
                            </td>
                            <td class="px-2.5 py-2">
                                <p class="font-medium text-foreground">
                                    {{ f.nombre }}
                                </p>
                                <p
                                    v-if="f.numero_empleado"
                                    class="text-[11px] text-muted-foreground"
                                >
                                    No. {{ f.numero_empleado }}
                                </p>
                            </td>
                            <td class="px-2.5 py-2">
                                <p>{{ f.sucursal || '—' }}</p>
                                <p
                                    v-if="f.empresa"
                                    class="text-[11px] text-muted-foreground"
                                >
                                    {{ f.empresa }}
                                </p>
                            </td>
                            <td class="px-2.5 py-2">
                                {{ f.puesto ?? '—' }}
                                <p
                                    v-if="f.departamento"
                                    class="text-[11px] text-muted-foreground"
                                >
                                    {{ f.departamento }}
                                </p>
                            </td>
                            <td class="px-2.5 py-2 whitespace-nowrap">
                                {{ formatearFecha(f.fecha_ingreso) }}
                            </td>
                            <td class="px-2.5 py-2">
                                <p class="whitespace-nowrap">
                                    {{ antiguedadCorta(f) }}
                                </p>
                                <p class="text-[11px] text-muted-foreground">
                                    {{ f.rango_etiqueta }}
                                </p>
                            </td>
                            <td class="max-w-48 px-2.5 py-2">
                                <p
                                    class="line-clamp-2"
                                    :title="f.motivo_original ?? ''"
                                >
                                    {{ f.motivo_original ?? '—' }}
                                </p>
                            </td>
                            <td class="max-w-52 px-2.5 py-2">
                                <p class="font-medium">{{ f.causal }}</p>
                                <p
                                    v-if="f.causal_catalogo"
                                    class="text-[11px] text-muted-foreground"
                                >
                                    Catálogo: {{ f.causal_catalogo }}
                                </p>
                            </td>
                            <td class="px-2.5 py-2">
                                <ChipEstado
                                    :etiqueta="f.clasificacion_etiqueta"
                                    :tono="tonoClasificacion(f.clasificacion)"
                                />
                            </td>
                            <td class="px-2.5 py-2">{{ f.estado }}</td>
                            <td class="px-2.5 py-2">
                                <span
                                    :class="
                                        cn(
                                            'rounded-full px-2 py-0.5 text-[11px]',
                                            f.fuente === 'sistema'
                                                ? 'bg-salvia text-primary'
                                                : 'bg-crema text-bronce',
                                        )
                                    "
                                    >{{ f.fuente_etiqueta }}</span
                                >
                            </td>
                            <td class="px-2.5 py-2">
                                <div class="flex flex-wrap gap-1">
                                    <ChipEstado
                                        v-for="a in f.alertas"
                                        :key="a"
                                        :etiqueta="
                                            ALERTAS_HISTORICO[a]?.etiqueta ?? a
                                        "
                                        :tono="
                                            ALERTAS_HISTORICO[a]?.tono ??
                                            'alerta'
                                        "
                                    />
                                    <span
                                        v-if="!f.alertas.length"
                                        class="text-muted-foreground"
                                        >—</span
                                    >
                                </div>
                            </td>
                            <td class="px-1 py-1 text-right">
                                <AccionesFilaRotacion
                                    :fila="f"
                                    :ver-historial="puede.ver_personas"
                                    @corregir-causal="
                                        abrirCorreccion(f, 'causal')
                                    "
                                    @completar-fecha="
                                        abrirCorreccion(f, 'fecha')
                                    "
                                    @historial="abrirCorreccion(f, 'historial')"
                                />
                            </td>
                        </tr>
                    </ListaAnimada>
                </table>
            </div>

            <!-- Móvil -->
            <template v-else>
                <div
                    class="-mx-1 scroll-x-limpio flex gap-1.5 px-1"
                    role="group"
                    aria-label="Ordenar"
                >
                    <button
                        v-for="c in COLUMNAS_ORDEN"
                        :key="c.col"
                        type="button"
                        :class="
                            cn(
                                'inline-flex h-8 shrink-0 items-center gap-1 rounded-full border px-3 text-xs',
                                orden === c.col
                                    ? 'border-primary/40 bg-menta text-primary'
                                    : 'border-border text-muted-foreground',
                            )
                        "
                        :aria-pressed="orden === c.col"
                        @click="ordenar(c.col)"
                    >
                        {{ c.etiqueta }}
                        <ArrowDown
                            v-if="orden === c.col && dir === 'desc'"
                            class="size-3"
                        />
                        <ArrowUp v-else-if="orden === c.col" class="size-3" />
                    </button>
                </div>
                <ListaAnimada
                    tag="ul"
                    :cantidad="historico.data.length"
                    class="flex flex-col gap-2.5"
                    :class="nav.cargando.value && 'tabla-actualizando'"
                >
                    <li
                        v-for="f in historico.data"
                        :key="f.clave"
                        class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-card p-3.5"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-medium">{{ f.nombre }}</p>
                                <p class="text-xs text-muted-foreground">
                                    <template v-if="f.numero_empleado"
                                        >No. {{ f.numero_empleado }} ·
                                    </template>
                                    {{ f.sucursal || '—' }}
                                </p>
                            </div>
                            <AccionesFilaRotacion
                                :fila="f"
                                :ver-historial="puede.ver_personas"
                                @corregir-causal="abrirCorreccion(f, 'causal')"
                                @completar-fecha="abrirCorreccion(f, 'fecha')"
                                @historial="abrirCorreccion(f, 'historial')"
                            />
                        </div>
                        <dl class="grid grid-cols-2 gap-x-3 gap-y-1.5 text-xs">
                            <div>
                                <dt class="text-muted-foreground">Baja</dt>
                                <dd>{{ formatearFecha(f.fecha_baja) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Ingreso</dt>
                                <dd>{{ formatearFecha(f.fecha_ingreso) }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">Puesto</dt>
                                <dd>{{ f.puesto ?? '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground">
                                    Antigüedad
                                </dt>
                                <dd>{{ antiguedadCorta(f) }}</dd>
                            </div>
                            <div class="col-span-2">
                                <dt class="text-muted-foreground">
                                    Motivo original
                                </dt>
                                <dd>{{ f.motivo_original ?? '—' }}</dd>
                            </div>
                        </dl>
                        <p class="text-sm font-medium">{{ f.causal }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <ChipEstado
                                :etiqueta="f.clasificacion_etiqueta"
                                :tono="tonoClasificacion(f.clasificacion)"
                            />
                            <ChipEstado
                                :etiqueta="`${f.fuente_etiqueta} · ${f.estado}`"
                                tono="neutro"
                            />
                            <ChipEstado
                                v-for="a in f.alertas"
                                :key="a"
                                :etiqueta="ALERTAS_HISTORICO[a]?.etiqueta ?? a"
                                :tono="ALERTAS_HISTORICO[a]?.tono ?? 'alerta'"
                            />
                        </div>
                    </li>
                </ListaAnimada>
            </template>

            <PaginacionTabla
                :datos="historico"
                local
                :por-pagina="historico.per_page"
                clave="rotacion-historico"
                @cambiar-pagina="irAPagina"
                @cambiar-por-pagina="cambiarPorPagina"
            />
        </section>

        <CalidadDatoRotacion
            :calidad="calidad"
            :conciliacion="conciliacion"
            :puede-corregir="puede.corregir"
            :rapido-activo="desglose.rapido"
        />

        <DialogosCorreccionRotacion
            :fila="filaActiva"
            :modo="modo"
            :opciones="opciones"
            @cerrar="cerrarCorreccion"
        />
        <CargaHistoricoSheet
            v-if="puede.cargar_historico"
            v-model:open="cargaAbierta"
            :importacion="importacion"
        />
        <HomologacionSheet
            v-if="puede.corregir"
            v-model:open="homologacionAbierta"
            :catalogo="catalogo"
            :opciones="opciones"
        />
    </div>
</template>
