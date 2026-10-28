<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BadgeCheck,
    CalendarClock,
    CalendarDays,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    Eye,
    FileSignature,
    FileWarning,
    Hourglass,
    Inbox,
    ListFilter,
    PencilLine,
    Printer,
    RotateCcw,
    Send,
    Settings2,
    UserRound,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import RespuestasEvaluacionDialog from '@/components/evaluaciones/RespuestasEvaluacionDialog.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import type { Tono } from '@/lib/tonos';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as indexContratos } from '@/routes/rh/contratos';
import { asignar, index } from '@/routes/rh/evaluaciones';
import type {
    ConteosEvaluacionesPrueba,
    EstadoEvaluacionPrueba,
    FilaEvaluacionPrueba,
    PersonaPeriodoBase,
    PersonaPeriodoPrueba,
    PestanaEvaluacionesPrueba,
    RespuestaPaginada,
} from '@/types';

/**
 * «Evaluaciones de periodo de prueba» (FO-RH-EPP-001): UN solo módulo con
 * tres pestañas — Próximas / por asignar (quién está por terminar su periodo
 * y aún no tiene evaluación: RH la asigna a su jefe directo), En proceso
 * (esperando al jefe o por decidir RH) y Evaluadas (ver respuestas e
 * imprimir el PDF para firma). Capturar y decidir siguen en el ciclo
 * laboral de cada persona.
 */
const props = defineProps<{
    pestana: PestanaEvaluacionesPrueba;
    evaluaciones: RespuestaPaginada<FilaEvaluacionPrueba> | null;
    porAsignar: RespuestaPaginada<PersonaPeriodoPrueba> | null;
    sinDuracion: PersonaPeriodoBase[];
    diasAlerta: number;
    conteos: ConteosEvaluacionesPrueba;
    sucursales: { value: number; label: string }[];
    filtros: {
        busqueda: string;
        sucursal_id: number | null;
        solo_alerta: boolean;
        subfiltro: string;
    };
    puedeAutorizar: boolean;
    puedeAsignar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Evaluaciones de periodo de prueba', href: index() },
        ],
    },
});

// ─── Navegación (pestaña, chips, búsqueda, sucursal) ───────────────────

const busqueda = ref(props.filtros.busqueda ?? '');
const sucursal = ref<number | null>(props.filtros.sucursal_id ?? null);
let temporizador: ReturnType<typeof setTimeout> | undefined;

watch(
    () => props.filtros,
    (f) => {
        busqueda.value = f.busqueda ?? '';
        sucursal.value = f.sucursal_id ?? null;
    },
);

function ir(cambios: {
    tab?: PestanaEvaluacionesPrueba;
    subfiltro?: string;
    solo_alerta?: boolean;
}) {
    const tab = cambios.tab ?? props.pestana;
    const mismaPestana = tab === props.pestana;
    const consulta: Record<string, string | number> = { tab };

    if (busqueda.value.trim()) {
        consulta.busqueda = busqueda.value.trim();
    }

    if (sucursal.value) {
        consulta.sucursal_id = sucursal.value;
    }

    const subfiltro =
        cambios.subfiltro ?? (mismaPestana ? props.filtros.subfiltro : '');

    if (subfiltro) {
        consulta.subfiltro = subfiltro;
    }

    const soloAlerta =
        cambios.solo_alerta ??
        (mismaPestana ? props.filtros.solo_alerta : false);

    if (soloAlerta) {
        consulta.solo_alerta = 1;
    }

    seleccion.value = [];
    router.get(
        index.url(),
        { ...parametrosPorPaginaActuales(), ...consulta },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function buscar(valor: string) {
    busqueda.value = valor;
    clearTimeout(temporizador);
    temporizador = setTimeout(() => ir({}), 400);
}

function limpiar() {
    busqueda.value = '';
    sucursal.value = null;
    ir({ subfiltro: '', solo_alerta: false });
}

type DefPestana = {
    clave: PestanaEvaluacionesPrueba;
    etiqueta: string;
    icono: Component;
    conteo: number;
};

const pestanas = computed<DefPestana[]>(() => [
    {
        clave: 'proximas',
        etiqueta: 'Próximas / por asignar',
        icono: CalendarClock,
        conteo: props.conteos.por_asignar,
    },
    {
        clave: 'en_proceso',
        etiqueta: 'En proceso',
        icono: Hourglass,
        conteo: props.conteos.en_proceso,
    },
    {
        clave: 'evaluadas',
        etiqueta: 'Evaluadas',
        icono: BadgeCheck,
        conteo: props.conteos.evaluadas,
    },
]);

type Chip = {
    valor: string;
    etiqueta: string;
    conteo: number;
    icono: Component;
};

const chips = computed<Chip[]>(() => {
    if (props.pestana === 'en_proceso') {
        return [
            {
                valor: '',
                etiqueta: 'Todas',
                conteo: props.conteos.en_proceso,
                icono: ListFilter,
            },
            {
                valor: 'esperando_jefe',
                etiqueta: 'Esperando al jefe',
                conteo: props.conteos.esperando_jefe,
                icono: Hourglass,
            },
            {
                valor: 'por_decidir',
                etiqueta: 'Por decidir RH',
                conteo: props.conteos.por_decidir,
                icono: ClipboardCheck,
            },
        ];
    }

    if (props.pestana === 'evaluadas') {
        return [
            {
                valor: '',
                etiqueta: 'Todas',
                conteo: props.conteos.evaluadas,
                icono: ListFilter,
            },
            {
                valor: 'por_decidir',
                etiqueta: 'Por decidir RH',
                conteo: props.conteos.por_decidir,
                icono: ClipboardCheck,
            },
            {
                valor: 'autorizadas',
                etiqueta: 'Con decisión de RH',
                conteo: props.conteos.autorizadas,
                icono: BadgeCheck,
            },
        ];
    }

    return [
        {
            valor: '',
            etiqueta: 'Todos',
            conteo: props.conteos.por_asignar,
            icono: Users,
        },
        {
            valor: 'alerta',
            etiqueta: `Vencen en ${props.diasAlerta} días o menos`,
            conteo: props.conteos.por_asignar_alerta,
            icono: AlertTriangle,
        },
    ];
});

const chipActivo = computed(() =>
    props.pestana === 'proximas'
        ? props.filtros.solo_alerta
            ? 'alerta'
            : ''
        : props.filtros.subfiltro,
);

function elegirChip(valor: string) {
    if (props.pestana === 'proximas') {
        ir({ solo_alerta: valor === 'alerta' });

        return;
    }

    ir({ subfiltro: valor });
}

const filtrosActivos = computed(() => (sucursal.value ? 1 : 0));

// ─── Utilidades de presentación ────────────────────────────────────────

function fecha(valor: string | null): string {
    return valor
        ? new Date(`${valor.slice(0, 10)}T12:00:00`).toLocaleDateString(
              'es-MX',
              { day: 'numeric', month: 'short', year: 'numeric' },
          )
        : '—';
}

/** «Faltan 3 días», «Vence hoy», «Venció hace 5 días». */
function textoDias(dias: number | null): string {
    if (dias === null) {
        return 'Sin fecha límite';
    }

    if (dias === 0) {
        return 'Vence hoy';
    }

    if (dias < 0) {
        return Math.abs(dias) === 1
            ? 'Venció hace 1 día'
            : `Venció hace ${Math.abs(dias)} días`;
    }

    return dias === 1 ? 'Falta 1 día' : `Faltan ${dias} días`;
}

/** Urgencia por días restantes: ≤5 peligro, ≤ alerta configurada, resto tranquilo. */
function urgencia(dias: number | null): Tono {
    if (dias === null) {
        return 'neutro';
    }

    if (dias <= 5) {
        return 'peligro';
    }

    return dias <= props.diasAlerta ? 'alerta' : 'primario';
}

const CHIP_DIAS: Record<Tono, string> = {
    peligro: 'bg-danger-soft text-destructive',
    alerta: 'bg-warning-soft text-warning',
    primario: 'bg-salvia text-primary',
    exito: 'bg-success-soft text-success',
    info: 'bg-info-soft text-info',
    neutro: 'bg-muted text-muted-foreground',
    oro: 'bg-crema text-bronce',
};

/** Avance del periodo (0–100) para la barra de cada tarjeta. */
function avance(fila: PersonaPeriodoPrueba): number {
    const total = fila.dias_transcurridos + fila.dias_restantes;

    return total > 0
        ? Math.min(100, Math.round((fila.dias_transcurridos / total) * 100))
        : 100;
}

const ESTADO: Record<
    EstadoEvaluacionPrueba,
    { tono: Tono; icono: Component; etiqueta: string }
> = {
    pendiente: {
        tono: 'alerta',
        icono: Hourglass,
        etiqueta: 'Esperando al jefe',
    },
    devuelta: {
        tono: 'peligro',
        icono: RotateCcw,
        etiqueta: 'Devuelta al jefe',
    },
    capturada: {
        tono: 'info',
        icono: ClipboardCheck,
        etiqueta: 'Por decidir RH',
    },
    autorizada: {
        tono: 'exito',
        icono: BadgeCheck,
        etiqueta: 'Decidida por RH',
    },
};

function recomendacion(e: FilaEvaluacionPrueba): {
    etiqueta: string;
    tono: Tono;
    icono: Component;
} {
    if (e.recomienda_renovar === true) {
        return {
            etiqueta: 'Jefe: sí se renueva',
            tono: 'exito',
            icono: CheckCircle2,
        };
    }

    if (e.recomienda_renovar === false) {
        return {
            etiqueta: 'Jefe: no se renueva',
            tono: 'peligro',
            icono: FileWarning,
        };
    }

    return {
        etiqueta: 'Sin recomendación',
        tono: 'neutro',
        icono: ClipboardList,
    };
}

function porcentajeTotal(e: FilaEvaluacionPrueba): number {
    const r = e.respuestas;

    if (!r?.total || !r.total_maximo) {
        return 0;
    }

    return Math.min(100, Math.round((r.total / r.total_maximo) * 100));
}

function urlAccion(e: FilaEvaluacionPrueba, accion: string): string {
    return `${e.url}?accion=${accion}`;
}

// ─── Asignar evaluación (uno o varios) ─────────────────────────────────

const seleccion = ref<number[]>([]);
const confirmarAsignar = ref<PersonaPeriodoPrueba[]>([]);
const asignando = ref(false);

const asignablesPagina = computed(
    () => props.porAsignar?.data.filter((f) => f.puede_asignar) ?? [],
);

const todosSeleccionados = computed(
    () =>
        asignablesPagina.value.length > 0 &&
        asignablesPagina.value.every((f) => seleccion.value.includes(f.id)),
);

function alternar(id: number, marcado: boolean) {
    seleccion.value = marcado
        ? [...new Set([...seleccion.value, id])]
        : seleccion.value.filter((s) => s !== id);
}

function alternarTodos(marcado: boolean) {
    seleccion.value = marcado ? asignablesPagina.value.map((f) => f.id) : [];
}

function pedirAsignar(filas: PersonaPeriodoPrueba[]) {
    confirmarAsignar.value = filas.filter((f) => f.puede_asignar);
}

function asignarSeleccionadas() {
    pedirAsignar(
        (props.porAsignar?.data ?? []).filter((f) =>
            seleccion.value.includes(f.id),
        ),
    );
}

function confirmar() {
    if (confirmarAsignar.value.length === 0) {
        return;
    }

    router.post(
        asignar.url(),
        { colaboradores: confirmarAsignar.value.map((f) => f.id) },
        {
            preserveScroll: true,
            onStart: () => (asignando.value = true),
            onFinish: () => (asignando.value = false),
            onSuccess: () => {
                confirmarAsignar.value = [];
                seleccion.value = [];
            },
        },
    );
}

// ─── Ver respuestas y PDF para firma ───────────────────────────────────

const respuestasDe = ref<FilaEvaluacionPrueba | null>(null);
const visor = useVistaPreviaPdf();
const documentoActivo = ref<{ id: number; nombre: string; url: string } | null>(
    null,
);

function verPdf(e: FilaEvaluacionPrueba) {
    if (!e.pdf_url) {
        return;
    }

    void visor.abrir([
        {
            clave: `evaluacion-${e.id}`,
            nombre: `Evaluación de periodo de prueba · ${e.colaborador.nombre}`,
            url: e.pdf_url,
        },
    ]);
}

const ayuda = computed(
    () =>
        `Próximas: quién está por terminar su periodo de prueba (fecha de ingreso + duración del puesto) y aún no tiene evaluación. Se abre sola 15 días hábiles antes de que venza su contrato; con «Asignar evaluación» RH la abre antes y se le avisa a su jefe directo del organigrama. Entra en alerta cuando faltan ${props.diasAlerta} días naturales o menos. En proceso: esperando al jefe o por decidir RH. Evaluadas: ver respuestas e imprimir el FO-RH-EPP-001 para firma.`,
);
</script>

<template>
    <Head title="Evaluaciones de periodo de prueba" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            titulo="Evaluaciones de periodo de prueba"
            :icono="ClipboardCheck"
        >
            <Button variant="outline" class="w-full sm:w-auto" as-child>
                <Link :href="indexContratos()">
                    <FileSignature class="size-4" />
                    Contratos
                </Link>
            </Button>
        </CrudPageHeader>

        <!-- Resumen -->
        <section aria-label="Resumen" class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Periodo de prueba · FO-RH-EPP-001
                </h2>
                <AyudaBoton titulo="¿Cómo funciona?" :texto="ayuda" />
            </div>
            <div class="grid grid-cols-2 gap-2.5 sm:gap-3 lg:grid-cols-4">
                <TarjetaMetrica
                    etiqueta="Por asignar"
                    :valor="conteos.por_asignar"
                    :icono="CalendarClock"
                    tono="alerta"
                    :detalle="
                        conteos.por_asignar_alerta
                            ? `${conteos.por_asignar_alerta} vencen en ${diasAlerta} días o menos`
                            : 'Sin periodos en alerta'
                    "
                    clicable
                    :activa="pestana === 'proximas'"
                    @click="ir({ tab: 'proximas', solo_alerta: false })"
                />
                <TarjetaMetrica
                    etiqueta="En espera del jefe"
                    :valor="conteos.esperando_jefe"
                    :icono="Hourglass"
                    tono="info"
                    detalle="Pendientes o devueltas"
                    clicable
                    :activa="
                        pestana === 'en_proceso' &&
                        filtros.subfiltro === 'esperando_jefe'
                    "
                    @click="
                        ir({ tab: 'en_proceso', subfiltro: 'esperando_jefe' })
                    "
                />
                <TarjetaMetrica
                    etiqueta="Por decidir RH"
                    :valor="conteos.por_decidir"
                    :icono="ClipboardCheck"
                    tono="oro"
                    detalle="El jefe ya evaluó"
                    clicable
                    :activa="
                        pestana === 'en_proceso' &&
                        filtros.subfiltro === 'por_decidir'
                    "
                    @click="ir({ tab: 'en_proceso', subfiltro: 'por_decidir' })"
                />
                <TarjetaMetrica
                    etiqueta="Evaluadas este mes"
                    :valor="conteos.evaluadas_mes"
                    :icono="BadgeCheck"
                    tono="exito"
                    :detalle="`${conteos.evaluadas} con respuestas en total`"
                    clicable
                    :activa="pestana === 'evaluadas'"
                    @click="ir({ tab: 'evaluadas', subfiltro: '' })"
                />
            </div>
        </section>

        <!-- Pestañas -->
        <div
            class="-mx-1 scroll-x-limpio flex gap-1 px-1"
            role="tablist"
            aria-label="Secciones de evaluaciones"
        >
            <div
                class="flex w-full min-w-max gap-1 rounded-2xl border border-border/60 bg-card p-1 shadow-sm sm:w-auto"
            >
                <button
                    v-for="p in pestanas"
                    :key="p.clave"
                    type="button"
                    role="tab"
                    :aria-selected="pestana === p.clave"
                    :class="
                        cn(
                            'inline-flex h-11 flex-1 items-center justify-center gap-2 rounded-xl px-4 text-sm font-medium whitespace-nowrap transition sm:flex-none',
                            pestana === p.clave
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                        )
                    "
                    @click="
                        ir({ tab: p.clave, subfiltro: '', solo_alerta: false })
                    "
                >
                    <component :is="p.icono" class="size-4" />
                    {{ p.etiqueta }}
                    <span
                        class="rounded-full px-2 py-0.5 text-xs tabular-nums"
                        :class="
                            pestana === p.clave
                                ? 'bg-card/20'
                                : 'bg-muted text-foreground'
                        "
                        >{{ p.conteo }}</span
                    >
                </button>
            </div>
        </div>

        <!-- Búsqueda + sucursal + chips -->
        <div class="flex flex-col gap-3">
            <CrudToolbar
                :model-value="busqueda"
                placeholder="Buscar colaborador o número de empleado…"
                :contador-filtros-activos="filtrosActivos"
                titulo-filtros="Filtrar evaluaciones"
                @update:model-value="buscar"
                @limpiar="limpiar"
                @aplicar-filtros="ir({})"
            >
                <template #filtros>
                    <div>
                        <Label class="mb-1.5 text-xs text-muted-foreground"
                            >Sucursal</Label
                        >
                        <SelectSimple
                            v-model="sucursal"
                            :opciones="sucursales"
                            numerico
                            opcion-vacia="Todas"
                            placeholder="Todas"
                        />
                    </div>
                </template>
            </CrudToolbar>

            <div
                class="-mx-1 scroll-x-limpio flex gap-2 px-1 pb-1 sm:flex-wrap"
                role="toolbar"
                aria-label="Filtrar dentro de la sección"
            >
                <button
                    v-for="chip in chips"
                    :key="chip.valor"
                    type="button"
                    class="inline-flex h-10 shrink-0 items-center gap-2 rounded-full border px-4 text-sm font-medium transition"
                    :class="
                        chipActivo === chip.valor
                            ? 'border-primary bg-primary text-primary-foreground'
                            : 'border-border bg-card hover:bg-muted'
                    "
                    :aria-pressed="chipActivo === chip.valor"
                    @click="elegirChip(chip.valor)"
                >
                    <component :is="chip.icono" class="size-4" />
                    {{ chip.etiqueta }}
                    <span
                        class="rounded-full px-1.5 text-xs tabular-nums"
                        :class="
                            chipActivo === chip.valor
                                ? 'bg-card/20'
                                : 'bg-muted'
                        "
                        >{{ chip.conteo }}</span
                    >
                </button>
            </div>
        </div>

        <!-- ═════════ Próximas / por asignar ═════════ -->
        <template v-if="pestana === 'proximas' && porAsignar">
            <div
                v-if="puedeAsignar && asignablesPagina.length"
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-crema/50 p-3 sm:flex-row sm:items-center sm:justify-between"
            >
                <label class="flex items-center gap-2 text-sm font-medium">
                    <Checkbox
                        :model-value="todosSeleccionados"
                        aria-label="Seleccionar todos los que se pueden asignar"
                        @update:model-value="(v) => alternarTodos(!!v)"
                    />
                    Seleccionar los que ya se pueden asignar ({{
                        asignablesPagina.length
                    }})
                </label>
                <Button
                    class="h-11 sm:h-10"
                    :disabled="seleccion.length === 0"
                    @click="asignarSeleccionadas"
                >
                    <Send class="size-4" />
                    Asignar evaluación
                    {{ seleccion.length ? `(${seleccion.length})` : '' }}
                </Button>
            </div>

            <EmptyState
                v-if="!porAsignar.data.length"
                :icono="CalendarClock"
                titulo="Nadie por asignar"
                descripcion="Nadie en periodo de prueba está sin evaluación con estos filtros."
            />

            <ul
                v-else
                class="rejilla-auto"
                style="--rejilla-min: 20rem"
                aria-label="Colaboradores por asignar evaluación"
            >
                <li
                    v-for="fila in porAsignar.data"
                    :key="fila.id"
                    class="flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-card shadow-sm transition"
                    :class="
                        seleccion.includes(fila.id)
                            ? 'border-primary ring-2 ring-primary/20'
                            : urgencia(fila.dias_restantes) === 'peligro'
                              ? 'border-destructive/40'
                              : fila.alerta
                                ? 'border-warning/50'
                                : 'border-border/60'
                    "
                >
                    <div class="flex items-start gap-3 p-4">
                        <Checkbox
                            v-if="puedeAsignar && fila.puede_asignar"
                            class="mt-1"
                            :model-value="seleccion.includes(fila.id)"
                            :aria-label="`Seleccionar a ${fila.nombre}`"
                            @update:model-value="(v) => alternar(fila.id, !!v)"
                        />
                        <ColaboradorAvatar
                            :nombre="fila.nombre"
                            :foto-url="fila.foto_url"
                            tamano="lg"
                        />
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="fila.ciclo_url"
                                class="block truncate text-base font-semibold hover:underline"
                                >{{ fila.nombre }}</Link
                            >
                            <p class="truncate text-sm text-muted-foreground">
                                {{ fila.puesto ?? 'Sin puesto' }} ·
                                {{ fila.sucursal ?? 'Sin sucursal' }}
                            </p>
                        </div>
                        <div
                            class="flex shrink-0 flex-col items-center rounded-2xl px-3 py-1.5"
                            :class="CHIP_DIAS[urgencia(fila.dias_restantes)]"
                            :title="`Su periodo de prueba termina el ${fecha(fila.fin_periodo)}`"
                        >
                            <span
                                class="text-2xl leading-none font-bold tabular-nums"
                                >{{ fila.dias_restantes }}</span
                            >
                            <span class="text-[11px] font-medium">
                                {{ fila.dias_restantes === 1 ? 'día' : 'días' }}
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col gap-3 px-4 pb-4">
                        <div class="flex flex-col gap-1.5">
                            <div
                                class="h-2 overflow-hidden rounded-full bg-muted"
                                role="progressbar"
                                :aria-valuenow="avance(fila)"
                                aria-valuemin="0"
                                aria-valuemax="100"
                                aria-label="Avance del periodo de prueba"
                            >
                                <div
                                    class="h-full rounded-full"
                                    :class="
                                        urgencia(fila.dias_restantes) ===
                                        'peligro'
                                            ? 'bg-destructive'
                                            : fila.alerta
                                              ? 'bg-warning'
                                              : 'bg-primary'
                                    "
                                    :style="{ width: `${avance(fila)}%` }"
                                />
                            </div>
                            <div
                                class="flex justify-between gap-2 text-xs text-muted-foreground tabular-nums"
                            >
                                <span class="inline-flex items-center gap-1">
                                    <CalendarDays class="size-3.5" />
                                    Ingreso {{ fecha(fila.fecha_ingreso) }}
                                </span>
                                <span
                                    class="inline-flex items-center gap-1 font-semibold"
                                    :title="fila.fuente_etiqueta"
                                >
                                    <Hourglass class="size-3.5" />
                                    {{
                                        fila.dias_restantes === 0
                                            ? 'Vence hoy'
                                            : `Termina ${fecha(fila.fin_periodo)}`
                                    }}
                                </span>
                            </div>
                        </div>

                        <p
                            class="flex items-center gap-2 rounded-xl bg-[var(--mrl-fondo)] px-3 py-2 text-sm"
                        >
                            <UserRound
                                class="size-4 shrink-0 text-muted-foreground"
                            />
                            <span class="min-w-0 truncate">
                                <span class="text-muted-foreground"
                                    >Evaluará:</span
                                >
                                <strong v-if="fila.evaluador">{{
                                    fila.evaluador
                                }}</strong>
                                <span v-else class="text-warning"
                                    >sin jefe en el organigrama (se avisa a
                                    RH)</span
                                >
                            </span>
                        </p>

                        <p
                            v-if="
                                !fila.puede_asignar && fila.motivo_no_asignable
                            "
                            class="flex items-start gap-2 rounded-xl bg-warning-soft px-3 py-2 text-xs text-warning"
                        >
                            <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                            <span
                                >Aún no se puede asignar:
                                {{ fila.motivo_no_asignable }}</span
                            >
                        </p>
                    </div>

                    <footer
                        class="mt-auto flex flex-col gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3 sm:flex-row"
                    >
                        <Button
                            variant="outline"
                            class="h-11 flex-1 sm:h-10"
                            as-child
                        >
                            <Link :href="fila.ciclo_url">
                                <CalendarClock class="size-4" />
                                Ciclo laboral
                            </Link>
                        </Button>
                        <Button
                            v-if="puedeAsignar"
                            class="h-11 flex-1 sm:h-10"
                            :disabled="!fila.puede_asignar"
                            :title="
                                fila.puede_asignar
                                    ? 'Crear la evaluación ahora y avisar a su jefe'
                                    : (fila.motivo_no_asignable ?? '')
                            "
                            @click="pedirAsignar([fila])"
                        >
                            <Send class="size-4" />
                            Asignar evaluación
                        </Button>
                    </footer>
                </li>
            </ul>

            <PaginacionTabla :datos="porAsignar" />

            <section
                v-if="sinDuracion.length"
                class="flex flex-col gap-3 rounded-2xl border border-border/60 bg-crema/60 p-4"
            >
                <div class="flex items-center gap-2">
                    <Settings2 class="size-4 text-bronce" />
                    <h2 class="text-sm font-semibold text-bronce">
                        Sin duración de periodo configurada ({{
                            sinDuracion.length
                        }})
                    </h2>
                    <AyudaBoton
                        titulo="Puesto sin duración"
                        texto="No se puede calcular cuándo vence su periodo de prueba. Configura la duración del puesto en Configuración → Parámetros de RH."
                    />
                </div>
                <ul class="rejilla-auto gap-2" style="--rejilla-min: 16rem">
                    <li v-for="p in sinDuracion" :key="p.id">
                        <Link
                            :href="p.ciclo_url"
                            class="flex tarjeta-interactiva items-center gap-3 rounded-xl border border-border/60 bg-card p-3"
                        >
                            <ColaboradorAvatar
                                :nombre="p.nombre"
                                :foto-url="p.foto_url"
                                tamano="md"
                            />
                            <span class="min-w-0">
                                <span
                                    class="block truncate text-sm font-medium"
                                    >{{ p.nombre }}</span
                                >
                                <span
                                    class="block truncate text-xs text-muted-foreground"
                                    >{{ p.puesto ?? 'Sin puesto' }} · ingreso
                                    {{ fecha(p.fecha_ingreso) }}</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </template>

        <!-- ═════════ En proceso / Evaluadas ═════════ -->
        <template v-else-if="evaluaciones">
            <EmptyState
                v-if="!evaluaciones.data.length"
                :icono="pestana === 'evaluadas' ? BadgeCheck : Inbox"
                :titulo="
                    pestana === 'evaluadas'
                        ? 'Todavía no hay evaluaciones contestadas'
                        : 'Nada en proceso'
                "
                :descripcion="
                    pestana === 'evaluadas'
                        ? 'Aquí aparecen en cuanto el jefe inmediato contesta la evaluación.'
                        : 'No hay evaluaciones esperando al jefe ni por decidir con estos filtros.'
                "
            />

            <ul
                v-else
                class="rejilla-auto"
                style="--rejilla-min: 21rem"
                :aria-label="
                    pestana === 'evaluadas'
                        ? 'Evaluaciones contestadas'
                        : 'Evaluaciones en proceso'
                "
            >
                <li
                    v-for="evaluacion in evaluaciones.data"
                    :key="evaluacion.id"
                    class="flex min-w-0 flex-col overflow-hidden rounded-2xl border bg-card shadow-sm"
                    :class="
                        pestana === 'en_proceso' &&
                        urgencia(evaluacion.dias_restantes) === 'peligro'
                            ? 'border-destructive/40'
                            : 'border-border/60'
                    "
                >
                    <div class="flex items-start gap-3 p-4">
                        <ColaboradorAvatar
                            :nombre="evaluacion.colaborador.nombre"
                            :foto-url="evaluacion.colaborador.foto_url"
                            tamano="lg"
                        />
                        <div class="min-w-0 flex-1">
                            <Link
                                :href="evaluacion.url"
                                class="block truncate text-base font-semibold hover:underline"
                                >{{ evaluacion.colaborador.nombre }}</Link
                            >
                            <p class="truncate text-sm text-muted-foreground">
                                {{ evaluacion.puesto ?? 'Sin puesto' }} ·
                                {{ evaluacion.sucursal ?? 'Sin sucursal' }}
                            </p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <ChipEstado
                                    :etiqueta="
                                        ESTADO[evaluacion.estado].etiqueta
                                    "
                                    :tono="ESTADO[evaluacion.estado].tono"
                                    :icono="ESTADO[evaluacion.estado].icono"
                                    :titulo="evaluacion.estado_etiqueta"
                                />
                                <ChipEstado
                                    v-if="evaluacion.respuestas"
                                    :etiqueta="
                                        recomendacion(evaluacion).etiqueta
                                    "
                                    :tono="recomendacion(evaluacion).tono"
                                    :icono="recomendacion(evaluacion).icono"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- En proceso: plazo y evaluador -->
                    <div
                        v-if="!evaluacion.respuestas"
                        class="grid grid-cols-2 gap-2 px-4 pb-4"
                    >
                        <div
                            class="flex flex-col gap-1 rounded-xl p-3"
                            :class="
                                CHIP_DIAS[urgencia(evaluacion.dias_restantes)]
                            "
                        >
                            <span
                                class="flex items-center gap-1 text-xs opacity-80"
                            >
                                <CalendarClock class="size-3.5" />
                                {{ fecha(evaluacion.fecha_limite) }}
                            </span>
                            <span class="text-sm font-semibold">{{
                                textoDias(evaluacion.dias_restantes)
                            }}</span>
                        </div>
                        <div
                            class="flex min-w-0 flex-col gap-1 rounded-xl bg-[var(--mrl-fondo)] p-3"
                        >
                            <span
                                class="flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <UserRound class="size-3.5" />
                                Evalúa
                            </span>
                            <span class="truncate text-sm font-semibold">{{
                                evaluacion.evaluador ?? 'RH (sin jefe)'
                            }}</span>
                        </div>
                    </div>

                    <!-- Con respuestas: total contra el máximo -->
                    <div v-else class="flex flex-col gap-2 px-4 pb-4">
                        <div class="flex items-end justify-between gap-3">
                            <p
                                class="text-3xl leading-none font-bold text-primary tabular-nums"
                            >
                                {{ evaluacion.respuestas.total ?? '—' }}
                                <span
                                    v-if="evaluacion.respuestas.total_maximo"
                                    class="text-sm font-medium text-muted-foreground"
                                    >/
                                    {{ evaluacion.respuestas.total_maximo }}
                                    pts</span
                                >
                            </p>
                            <p class="text-right text-xs text-muted-foreground">
                                Evaluó
                                {{
                                    evaluacion.respuestas.evaluador ??
                                    evaluacion.evaluador ??
                                    '—'
                                }}<br />{{
                                    fecha(
                                        evaluacion.respuestas.fecha_evaluacion,
                                    )
                                }}
                            </p>
                        </div>
                        <div
                            v-if="evaluacion.respuestas.total_maximo"
                            class="h-2 overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full"
                                :class="
                                    evaluacion.recomienda_renovar
                                        ? 'bg-success'
                                        : 'bg-destructive/70'
                                "
                                :style="{
                                    width: `${porcentajeTotal(evaluacion)}%`,
                                }"
                            />
                        </div>
                        <p
                            v-if="evaluacion.decision_renovar !== null"
                            class="text-xs font-medium"
                            :class="
                                evaluacion.decision_renovar
                                    ? 'text-success'
                                    : 'text-destructive'
                            "
                        >
                            Decisión RH:
                            {{
                                evaluacion.decision_renovar
                                    ? 'se renueva'
                                    : 'no se renueva'
                            }}
                        </p>
                    </div>

                    <div
                        v-if="evaluacion.documento_renovacion"
                        class="mx-4 mb-4 flex flex-col gap-3 rounded-xl bg-success-soft p-3"
                    >
                        <p
                            class="flex items-center gap-2 text-sm font-semibold text-success"
                        >
                            <FileSignature class="size-5" />
                            Contrato indeterminado listo
                        </p>
                        <BotonVistaPrevia
                            class="h-10"
                            texto="Ver contrato"
                            @click="
                                documentoActivo =
                                    evaluacion.documento_renovacion
                            "
                        />
                    </div>

                    <footer
                        class="mt-auto grid gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3"
                        :class="evaluacion.respuestas ? 'sm:grid-cols-2' : ''"
                    >
                        <template v-if="evaluacion.respuestas">
                            <Button
                                variant="outline"
                                class="h-11 sm:h-10"
                                @click="respuestasDe = evaluacion"
                            >
                                <Eye class="size-4" />
                                Ver respuestas
                            </Button>
                            <Button
                                variant="outline"
                                class="h-11 sm:h-10"
                                :disabled="visor.cargando.value"
                                @click="verPdf(evaluacion)"
                            >
                                <Printer class="size-4" />
                                PDF para firma
                            </Button>
                        </template>
                        <Button
                            v-if="evaluacion.puede_decidir"
                            class="h-11 sm:h-10"
                            :class="evaluacion.respuestas && 'sm:col-span-2'"
                            as-child
                        >
                            <Link
                                :href="
                                    urlAccion(
                                        evaluacion,
                                        'autorizar_evaluacion',
                                    )
                                "
                            >
                                <ClipboardCheck class="size-4" />
                                Revisar y decidir
                            </Link>
                        </Button>
                        <Button
                            v-else-if="evaluacion.puede_capturar"
                            class="h-11 sm:h-10"
                            as-child
                        >
                            <Link
                                :href="
                                    urlAccion(evaluacion, 'capturar_evaluacion')
                                "
                            >
                                <PencilLine class="size-4" />
                                {{
                                    evaluacion.estado === 'devuelta'
                                        ? 'Corregir evaluación'
                                        : 'Contestar evaluación'
                                }}
                            </Link>
                        </Button>
                        <Button
                            v-else-if="!evaluacion.respuestas"
                            variant="outline"
                            class="h-11 sm:h-10"
                            as-child
                        >
                            <Link :href="evaluacion.url">
                                <CalendarClock class="size-4" />
                                Abrir ciclo laboral
                            </Link>
                        </Button>
                    </footer>
                </li>
            </ul>

            <PaginacionTabla :datos="evaluaciones" />
        </template>
    </div>

    <!-- Confirmar «Asignar evaluación» -->
    <Dialog
        :open="confirmarAsignar.length > 0"
        @update:open="
            (v: boolean) => !v && !asignando && (confirmarAsignar = [])
        "
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>
                    {{
                        confirmarAsignar.length === 1
                            ? 'Asignar evaluación'
                            : `Asignar ${confirmarAsignar.length} evaluaciones`
                    }}
                </DialogTitle>
                <DialogDescription>
                    Se crea la evaluación de periodo de prueba (FO-RH-EPP-001)
                    ahora mismo y se le avisa a su jefe directo del organigrama
                    para que la conteste.
                </DialogDescription>
            </DialogHeader>
            <ul
                class="flex max-h-72 flex-col divide-y divide-border/60 overflow-y-auto rounded-xl border border-border/60"
            >
                <li
                    v-for="fila in confirmarAsignar"
                    :key="fila.id"
                    class="flex items-center gap-3 p-3"
                >
                    <ColaboradorAvatar
                        :nombre="fila.nombre"
                        :foto-url="fila.foto_url"
                        tamano="md"
                    />
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ fila.nombre }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            Evaluará:
                            {{
                                fila.evaluador ??
                                'RH (sin jefe en el organigrama)'
                            }}
                        </p>
                    </div>
                    <span
                        class="shrink-0 rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                        :class="CHIP_DIAS[urgencia(fila.dias_restantes)]"
                        >{{ textoDias(fila.dias_restantes) }}</span
                    >
                </li>
            </ul>
            <DialogFooter class="gap-2">
                <Button
                    variant="outline"
                    :disabled="asignando"
                    @click="confirmarAsignar = []"
                    >Cancelar</Button
                >
                <Button :disabled="asignando" @click="confirmar">
                    <Send class="size-4" />
                    {{ asignando ? 'Asignando…' : 'Asignar y avisar' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <RespuestasEvaluacionDialog
        :open="respuestasDe !== null"
        :evaluacion="respuestasDe"
        :cargando-pdf="visor.cargando.value"
        @update:open="(v: boolean) => !v && (respuestasDe = null)"
        @ver-pdf="verPdf"
    />

    <!-- Vista previa del PDF para firma (antes de imprimir o descargar) -->
    <DocumentPreviewDialog
        :open="visor.abierto.value"
        :archivos="visor.archivos.value"
        :indice="visor.indice.value"
        :cargando="visor.cargando.value"
        :titulo-carga="visor.tituloCarga.value"
        recargable
        @update:open="visor.alCambiarAbierto"
        @recargar="visor.recargar"
        @update:indice="(i: number) => (visor.indice.value = i)"
    />

    <!-- Contrato indeterminado ya generado -->
    <DocumentPreviewDialog
        :open="documentoActivo !== null"
        :preview-url="documentoActivo?.url ?? null"
        :nombre="documentoActivo?.nombre ?? 'Contrato indeterminado.pdf'"
        tipo="pdf"
        @update:open="
            (abierto: boolean) => !abierto && (documentoActivo = null)
        "
    />
</template>
