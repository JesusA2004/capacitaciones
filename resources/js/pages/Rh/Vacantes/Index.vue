<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Briefcase,
    Building2,
    CalendarClock,
    FilterX,
    Hourglass,
    Layers,
    MapPin,
    UserRoundX,
    UserSearch,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudEmptyState from '@/components/DataTable/CrudEmptyState.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { usePaginacionLocal } from '@/composables/usePaginacion';
import { usePermisos } from '@/composables/usePermisos';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { show as showSucursal } from '@/routes/administracion/sucursales';
import { index as indexCandidatos } from '@/routes/rh/candidatos';
import { exportarExcel, exportarPdf, index } from '@/routes/rh/vacantes';
import type { VacanteItem, VacantesResumen } from '@/types';

/**
 * Vacantes: QUÉ puestos faltan por cubrir y dónde (una fila por vacante
 * real), no la plantilla por sucursal — esa vive en el detalle de cada
 * sucursal (Administración > Sucursales). Las vacantes se abren y cierran
 * solas con headcount (docs/HEADCOUNT_Y_VACANTES.md).
 */
type Opcion = { id: number; nombre: string };

const props = defineProps<{
    vacantes: VacanteItem[];
    resumen: VacantesResumen;
    filtros: {
        busqueda?: string;
        sucursal_id?: string;
        puesto_id?: string;
        departamento_id?: string;
        estado?: string;
    };
    opciones: {
        sucursales: Opcion[];
        departamentos: Opcion[];
        puestos: Opcion[];
        estados: { valor: string; etiqueta: string }[];
    };
    /** vacantes.exportar (Roles y permisos). */
    puedeExportar?: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Vacantes', href: index.url() },
        ],
    },
});

const { tienePermiso } = usePermisos();
// La lista llega completa: se pagina en el navegador (mismo selector).
const paginacion = usePaginacionLocal(() => props.vacantes);
const busqueda = ref(props.filtros.busqueda ?? '');
const borrador = ref({
    sucursal_id: props.filtros.sucursal_id ?? '',
    puesto_id: props.filtros.puesto_id ?? '',
    departamento_id: props.filtros.departamento_id ?? '',
    estado: props.filtros.estado ?? '',
});
const sheetAbierto = ref(false);
let temporizador: ReturnType<typeof setTimeout> | undefined;

const activos = computed(
    () =>
        Object.values({ ...props.filtros, busqueda: undefined }).filter(Boolean)
            .length,
);

function parametros(extra: Record<string, string> = {}) {
    return Object.fromEntries(
        Object.entries({
            busqueda: busqueda.value,
            ...borrador.value,
            ...extra,
        }).filter(([, v]) => v),
    );
}

function navegar() {
    router.get(index.url(), parametros(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(navegar, 350);
});

function limpiar() {
    busqueda.value = '';
    borrador.value = {
        sucursal_id: '',
        puesto_id: '',
        departamento_id: '',
        estado: '',
    };
    navegar();
}

function urlExportar(
    destino: typeof exportarExcel | typeof exportarPdf,
): string {
    return `${destino.url()}?${new URLSearchParams(parametros()).toString()}`;
}

// Clic en un puesto o en una sucursal del resumen: filtra la lista.
function filtrarPor(puestoId: number | null, sucursalId: number | null = null) {
    borrador.value = {
        ...borrador.value,
        puesto_id: puestoId ? String(puestoId) : '',
        sucursal_id: sucursalId ? String(sucursalId) : '',
    };
    navegar();
}

// Áreas / departamentos con vacantes en lo que se está viendo (Contraloría,
// Sistemas, Ventas…): un clic filtra la lista por esa área.
const areas = computed(() => {
    const conteo = new Map<
        number,
        { id: number; nombre: string; plazas: number }
    >();

    for (const v of props.vacantes) {
        if (v.departamento_id === null || v.departamento === null) {
            continue;
        }

        const actual = conteo.get(v.departamento_id) ?? {
            id: v.departamento_id,
            nombre: v.departamento,
            plazas: 0,
        };
        actual.plazas += v.plazas_disponibles;
        conteo.set(v.departamento_id, actual);
    }

    return [...conteo.values()].sort((a, b) => b.plazas - a.plazas);
});

function filtrarPorArea(departamentoId: number | null) {
    borrador.value = {
        ...borrador.value,
        departamento_id:
            departamentoId === null ||
            borrador.value.departamento_id === String(departamentoId)
                ? ''
                : String(departamentoId),
    };
    navegar();
}

function plazas(n: number): string {
    return n === 1 ? '1 plaza' : `${n} plazas`;
}

function fecha(valor: string): string {
    return new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

const TONO_ESTADO: Record<string, Tono> = {
    abierta: 'alerta',
    en_reclutamiento: 'oro',
    con_candidatos: 'oro',
    en_revision: 'oro',
    cubierta: 'exito',
    cancelada: 'neutro',
};

// Antigüedad de la vacante: a partir de 30 días ya urge, a partir de 60 es
// crítica. Solo cambia el tono (franja y chip), nunca los datos.
function tonoAntiguedad(dias: number): Tono {
    if (dias >= 60) {
        return 'peligro';
    }

    return dias >= 30 ? 'alerta' : 'exito';
}

const FRANJA: Record<Tono, string> = {
    peligro: 'before:bg-destructive/70',
    alerta: 'before:bg-warning/70',
    exito: 'before:bg-success/60',
    info: 'before:bg-info/60',
    neutro: 'before:bg-border',
    oro: 'before:bg-oro/70',
    primario: 'before:bg-primary/60',
};

// Indicadores de lo que se está viendo (la lista llega completa, ya filtrada).
const sinCandidatos = computed(
    () => props.vacantes.filter((v) => v.candidatos_activos === 0).length,
);
const urgentes = computed(
    () => props.vacantes.filter((v) => v.dias_abierta >= 30).length,
);
const promedioDias = computed(() =>
    props.vacantes.length
        ? Math.round(
              props.vacantes.reduce((suma, v) => suma + v.dias_abierta, 0) /
                  props.vacantes.length,
          )
        : 0,
);
const maxPlazasPuesto = computed(() =>
    Math.max(1, ...props.resumen.por_puesto.map((p) => p.plazas)),
);
</script>

<template>
    <Head title="Vacantes" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader titulo="Vacantes" :icono="Briefcase">
            <CrudExportButtons
                v-if="props.puedeExportar"
                :url-excel="urlExportar(exportarExcel)"
                :url-pdf="urlExportar(exportarPdf)"
            />
        </CrudPageHeader>

        <!-- Indicadores de lo que se está viendo (respetan los filtros). -->
        <div
            v-if="vacantes.length > 0"
            class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <TarjetaMetrica
                etiqueta="Plazas por cubrir"
                :valor="resumen.plazas"
                :icono="Briefcase"
                tono="primario"
                :detalle="`${resumen.vacantes} ${resumen.vacantes === 1 ? 'vacante' : 'vacantes'} en ${resumen.sucursales} ${resumen.sucursales === 1 ? 'ubicación' : 'ubicaciones'}`"
            />
            <TarjetaMetrica
                etiqueta="Sin candidatos"
                :valor="sinCandidatos"
                :icono="UserRoundX"
                :tono="sinCandidatos > 0 ? 'alerta' : 'exito'"
                detalle="Vacantes sin nadie en proceso"
            />
            <TarjetaMetrica
                etiqueta="Abiertas 30 días o más"
                :valor="urgentes"
                :icono="Hourglass"
                :tono="urgentes > 0 ? 'peligro' : 'exito'"
                detalle="Las que más urge cubrir"
            />
            <TarjetaMetrica
                etiqueta="Días abiertas en promedio"
                :valor="promedioDias"
                :icono="CalendarClock"
                tono="oro"
                detalle="Desde que se abrió cada vacante"
            />
        </div>

        <!-- Totales concretos: cuántas plazas faltan de cada puesto y en
             qué sucursales (de lo que se está viendo). -->
        <section
            v-if="resumen.por_puesto.length > 0"
            data-tour="vacantes-resumen"
            class="rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
            aria-label="Resumen de vacantes"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold">Qué falta cubrir</h2>
                <p class="text-xs text-muted-foreground">
                    Clic en un puesto o en una ubicación para ver solo esas
                    vacantes
                </p>
            </div>
            <ul
                class="mt-3 grid entrada-escalonada gap-x-8 gap-y-1 xl:grid-cols-2"
            >
                <li
                    v-for="p in resumen.por_puesto"
                    :key="p.puesto_id ?? 0"
                    class="grid gap-2 rounded-xl px-2 py-2 transicion-ui hover:bg-menta/50 sm:grid-cols-[minmax(0,15rem)_minmax(0,1fr)] sm:items-center"
                >
                    <button
                        type="button"
                        class="group flex w-full min-w-0 items-center gap-3 text-left"
                        :title="`Ver solo ${p.puesto}`"
                        @click="filtrarPor(p.puesto_id)"
                    >
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-salvia text-sm font-bold text-primary tabular-nums"
                            >{{ p.plazas }}</span
                        >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate font-medium group-hover:text-primary"
                                >{{ p.puesto }}</span
                            >
                            <span class="truncate text-xs text-muted-foreground"
                                >{{ p.area ?? 'Sin área'
                                }}<template v-if="p.nota">
                                    · {{ p.nota }}</template
                                ></span
                            >
                            <span
                                class="mt-1 h-1 w-full overflow-hidden rounded-full bg-muted"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full crece-barra rounded-full bg-oro"
                                    :style="{
                                        width: `${(p.plazas * 100) / maxPlazasPuesto}%`,
                                    }"
                                />
                            </span>
                        </span>
                    </button>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="s in p.sucursales"
                            :key="s.sucursal_id ?? 0"
                            type="button"
                            class="inline-flex presionable items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs text-muted-foreground transicion-ui hover:border-oro/60 hover:text-foreground"
                            :class="
                                s.es_corporativo
                                    ? 'border-transparent bg-crema text-foreground'
                                    : 'bg-card'
                            "
                            :title="`${p.puesto} en ${s.sucursal}: ${plazas(s.plazas)}`"
                            @click="filtrarPor(p.puesto_id, s.sucursal_id)"
                        >
                            <Building2
                                v-if="s.es_corporativo"
                                class="size-3"
                                aria-hidden="true"
                            />
                            <MapPin v-else class="size-3" aria-hidden="true" />
                            {{ s.sucursal }}
                            <span class="font-semibold tabular-nums">{{
                                s.plazas
                            }}</span>
                        </button>
                    </div>
                </li>
            </ul>
        </section>

        <div
            data-tour="vacantes-filtros"
            class="flex min-w-0 items-center gap-2"
            role="search"
        >
            <CrudSearchInput
                v-model="busqueda"
                placeholder="Buscar puesto o sucursal…"
                class="min-w-0 flex-1 sm:w-72 sm:flex-none"
            />
            <CrudFilterSheet
                v-model:open="sheetAbierto"
                :contador-activos="activos"
                @aplicar="navegar"
                @limpiar="limpiar"
            >
                <div class="grid gap-1.5">
                    <Label for="f-estado">Estado</Label>
                    <SelectSimple
                        id="f-estado"
                        v-model="borrador.estado"
                        class="w-full"
                        :opciones="
                            opciones.estados.map((e) => ({
                                value: e.valor,
                                label: e.etiqueta,
                            }))
                        "
                        opcion-vacia="Activas (sin cubiertas ni canceladas)"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-sucursal">Sucursal</Label>
                    <SelectSimple
                        id="f-sucursal"
                        v-model="borrador.sucursal_id"
                        class="w-full"
                        :opciones="
                            opciones.sucursales.map((s) => ({
                                value: String(s.id),
                                label: s.nombre,
                            }))
                        "
                        opcion-vacia="Todas"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-puesto">Puesto</Label>
                    <SelectSimple
                        id="f-puesto"
                        v-model="borrador.puesto_id"
                        class="w-full"
                        :opciones="
                            opciones.puestos.map((p) => ({
                                value: String(p.id),
                                label: p.nombre,
                            }))
                        "
                        opcion-vacia="Todos"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="f-departamento">Área / departamento</Label>
                    <SelectSimple
                        id="f-departamento"
                        v-model="borrador.departamento_id"
                        class="w-full"
                        :opciones="
                            opciones.departamentos.map((d) => ({
                                value: String(d.id),
                                label: d.nombre,
                            }))
                        "
                        opcion-vacia="Todos"
                    />
                </div>
            </CrudFilterSheet>
            <Button
                v-if="activos > 0 || filtros.busqueda"
                variant="ghost"
                size="icon-sm"
                aria-label="Limpiar filtros"
                title="Limpiar filtros"
                @click="limpiar"
            >
                <FilterX class="size-4" />
            </Button>
        </div>

        <!-- Áreas con vacantes: Contraloría, Sistemas, Ventas… (clic = filtrar). -->
        <div
            v-if="areas.length > 1 || filtros.departamento_id"
            class="flex flex-wrap items-center gap-1.5"
            aria-label="Filtrar por área"
        >
            <span
                class="inline-flex items-center gap-1 text-xs font-medium text-muted-foreground"
            >
                <Layers class="size-3.5" aria-hidden="true" /> Área:
            </span>
            <button
                v-for="a in areas"
                :key="a.id"
                type="button"
                class="tarjeta-interactiva rounded-full border bg-card px-2.5 py-0.5 text-xs"
                :class="
                    filtros.departamento_id === String(a.id)
                        ? 'border-transparent bg-salvia font-semibold'
                        : 'text-muted-foreground'
                "
                :aria-pressed="filtros.departamento_id === String(a.id)"
                @click="filtrarPorArea(a.id)"
            >
                {{ a.nombre }}
                <span class="font-semibold tabular-nums">{{ a.plazas }}</span>
            </button>
            <button
                v-if="filtros.departamento_id"
                type="button"
                class="rounded-full enlace-animado px-2 py-0.5 text-xs text-muted-foreground"
                @click="filtrarPorArea(null)"
            >
                Todas las áreas
            </button>
        </div>

        <CrudEmptyState
            v-if="vacantes.length === 0"
            data-tour="vacantes-lista"
            :icono="Briefcase"
            :titulo="
                activos > 0 || filtros.busqueda
                    ? 'No hay vacantes con estos filtros'
                    : 'Aún no hay vacantes registradas'
            "
            :descripcion="
                activos > 0 || filtros.busqueda
                    ? 'Prueba con otros filtros o límpialos para ver todas las vacantes.'
                    : 'Las vacantes se abren solas cuando una sucursal tiene menos personas que su plantilla autorizada.'
            "
        />

        <!-- Una fila por vacante: puesto primero (es lo que se busca cubrir). -->
        <ul
            v-else
            data-tour="vacantes-lista"
            class="@container divide-y divide-border/60 overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            aria-label="Vacantes"
        >
            <li
                v-for="vacante in paginacion.filasPagina.value"
                :key="vacante.id"
                :class="
                    cn(
                        'relative grid gap-3 p-4 pl-5 transicion-ui before:absolute before:inset-y-3 before:left-0 before:w-1 before:rounded-r-full hover:bg-menta/40 @3xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_minmax(0,1fr)_auto] @3xl:items-center',
                        FRANJA[tonoAntiguedad(vacante.dias_abierta)],
                    )
                "
            >
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 font-semibold">
                        {{ vacante.puesto ?? 'Puesto sin definir' }}
                        <ChipEstado
                            :etiqueta="vacante.estado_etiqueta"
                            :tono="TONO_ESTADO[vacante.estado] ?? 'neutro'"
                        />
                        <Badge
                            v-if="!vacante.generada_automaticamente"
                            variant="secondary"
                            >Manual</Badge
                        >
                    </p>
                    <p
                        v-if="vacante.puesto_nota"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        {{ vacante.puesto_nota }}
                    </p>
                    <p
                        class="mt-0.5 flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                    >
                        <span
                            v-if="vacante.es_corporativo"
                            class="inline-flex items-center gap-1 rounded-full bg-crema px-2 py-0.5 text-xs font-medium text-foreground"
                        >
                            <Building2 class="size-3" aria-hidden="true" />
                            Corporativo
                        </span>
                        <MapPin v-else class="size-3.5 shrink-0" />
                        <template v-if="!vacante.es_corporativo">
                            <Link
                                v-if="
                                    vacante.sucursal_id &&
                                    tienePermiso('sucursales.administrar')
                                "
                                :href="showSucursal.url(vacante.sucursal_id)"
                                class="enlace-animado hover:text-foreground"
                                >{{ vacante.sucursal }}</Link
                            >
                            <span v-else>{{
                                vacante.sucursal ?? 'Sin sucursal'
                            }}</span>
                        </template>
                        <template v-if="vacante.departamento"
                            ><span aria-hidden="true">·</span
                            ><span class="font-medium text-foreground/80">{{
                                vacante.departamento
                            }}</span></template
                        >
                    </p>
                </div>

                <div class="text-sm">
                    <p class="font-semibold tabular-nums">
                        <span class="text-lg text-primary">{{
                            vacante.plazas_disponibles
                        }}</span>
                        {{
                            vacante.plazas_disponibles === 1
                                ? 'plaza por cubrir'
                                : 'plazas por cubrir'
                        }}
                    </p>
                    <p
                        v-if="vacante.plantilla_autorizada !== null"
                        class="text-xs text-muted-foreground"
                    >
                        {{ vacante.plantilla_actual }} de
                        {{ vacante.plantilla_autorizada }} ocupadas ·
                        {{ vacante.faltantes_reales ?? 0 }}
                        {{
                            vacante.faltantes_reales === 1
                                ? 'vacante en plantilla'
                                : 'vacantes en plantilla'
                        }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ vacante.motivo }}
                    </p>
                </div>

                <div class="flex flex-col items-start gap-1 text-sm">
                    <ChipEstado
                        :icono="Hourglass"
                        :tono="tonoAntiguedad(vacante.dias_abierta)"
                        :etiqueta="
                            vacante.dias_abierta === 0
                                ? 'Abierta hoy'
                                : `Abierta hace ${vacante.dias_abierta} ${vacante.dias_abierta === 1 ? 'día' : 'días'}`
                        "
                    />
                    <p class="text-xs text-muted-foreground">
                        Desde el {{ fecha(vacante.fecha_apertura) }}
                    </p>
                </div>

                <div class="flex items-center gap-2 @3xl:justify-end">
                    <Button
                        as-child
                        variant="outline"
                        size="sm"
                        :class="
                            vacante.candidatos_activos === 0
                                ? 'border-dashed text-muted-foreground'
                                : 'border-primary/30 bg-salvia/40 text-primary'
                        "
                    >
                        <Link
                            :href="
                                indexCandidatos.url({
                                    query: { vacante_id: vacante.id },
                                })
                            "
                        >
                            <UserSearch class="size-4" />
                            {{ vacante.candidatos_activos }}
                            {{
                                vacante.candidatos_activos === 1
                                    ? 'candidato'
                                    : 'candidatos'
                            }}
                        </Link>
                    </Button>
                </div>
            </li>
        </ul>

        <PaginacionTabla
            local
            :datos="paginacion.datos.value"
            :por-pagina="paginacion.porPagina.value"
            @cambiar-pagina="paginacion.irAPagina"
            @cambiar-por-pagina="paginacion.cambiarPorPagina"
        />
    </div>
</template>
