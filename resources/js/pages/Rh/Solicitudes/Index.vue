<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlarmClock,
    AlertTriangle,
    Archive,
    ArrowUpRight,
    Baby,
    Cake,
    CalendarDays,
    CircleCheckBig,
    CircleSlash,
    ClipboardList,
    Clock,
    Eye,
    FileCheck2,
    FileText,
    Flower2,
    GripVertical,
    HeartPulse,
    Inbox,
    Landmark,
    LogOut,
    MapPin,
    Palmtree,
    Paperclip,
    PencilLine,
    PenLine,
    ShieldAlert,
    ShieldCheck,
    UserMinus,
    UserPen,
    Wallet,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import type { Component } from 'vue';
import type { DraggableEvent } from 'vue-draggable-plus';
import { VueDraggable } from 'vue-draggable-plus';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { useFiltros } from '@/composables/useFiltros';
import { useKanbanTransition } from '@/composables/useKanbanTransition';
import {
    actualizarEstado,
    exportarExcel,
    exportarPdf,
    index,
    show,
} from '@/routes/rh/solicitudes';
import type {
    OpcionesSolicitudes,
    SolicitudInternaItem,
    TipoSolicitudInterna,
} from '@/types';

const props = defineProps<{
    solicitudes: SolicitudInternaItem[];
    solicitudesResumen: { total: number; mostradas: number; limite: number };
    filtros: {
        tipo?: string;
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        revisado_por?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    tipos: TipoSolicitudInterna[];
    opciones: OpcionesSolicitudes;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Solicitudes', href: '' }],
    },
});

const { mostrarExito, mostrarError } = useAlertas();

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        tipo: props.filtros.tipo ?? '',
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        revisado_por: props.filtros.revisado_por ?? '',
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

// --- Tablero Kanban: columnas por fase del flujo de revisión ---
// Tonos suaves y sin rojo: "Rechazada" usa pizarra (es un cierre, no una
// alarma) para que el tablero no se vea agresivo.
type ColumnaDefinicion = {
    estado: string;
    titulo: string;
    icono: Component;
    /** Fondo + texto del ícono y del contador. */
    suave: string;
    /** Fondo tenue del encabezado de la columna. */
    cabecera: string;
    /** Franja lateral de cada tarjeta. */
    barra: string;
};

const COLUMNAS: ColumnaDefinicion[] = [
    {
        estado: 'enviada',
        titulo: 'Pendientes',
        icono: Inbox,
        suave: 'bg-sky-100 text-sky-700 dark:bg-sky-500/20 dark:text-sky-300',
        cabecera: 'bg-sky-50/80 dark:bg-sky-500/5',
        barra: 'bg-sky-400',
    },
    {
        estado: 'en_revision',
        titulo: 'En revisión',
        icono: Eye,
        suave: 'bg-indigo-100 text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300',
        cabecera: 'bg-indigo-50/80 dark:bg-indigo-500/5',
        barra: 'bg-indigo-400',
    },
    {
        estado: 'requiere_correccion',
        titulo: 'Requiere corrección',
        icono: PencilLine,
        suave: 'bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300',
        cabecera: 'bg-amber-50/80 dark:bg-amber-500/5',
        barra: 'bg-amber-400',
    },
    {
        estado: 'aprobada',
        titulo: 'Aprobadas',
        icono: CircleCheckBig,
        suave: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300',
        cabecera: 'bg-emerald-50/80 dark:bg-emerald-500/5',
        barra: 'bg-emerald-400',
    },
    {
        estado: 'rechazada',
        titulo: 'No aprobadas',
        icono: CircleSlash,
        suave: 'bg-slate-200 text-slate-700 dark:bg-slate-500/25 dark:text-slate-300',
        cabecera: 'bg-slate-100/80 dark:bg-slate-500/5',
        barra: 'bg-slate-400',
    },
    {
        estado: 'cerrada',
        titulo: 'Cerradas',
        icono: Archive,
        suave: 'bg-teal-100 text-teal-700 dark:bg-teal-500/20 dark:text-teal-300',
        cabecera: 'bg-teal-50/80 dark:bg-teal-500/5',
        barra: 'bg-teal-400',
    },
];

const CHIP_BASE =
    'inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium';

const CHIP = {
    violeta: `${CHIP_BASE} bg-violet-100 text-violet-700 dark:bg-violet-500/20 dark:text-violet-300`,
    naranja: `${CHIP_BASE} bg-orange-100 text-orange-700 dark:bg-orange-500/20 dark:text-orange-300`,
    ambar: `${CHIP_BASE} bg-amber-100 text-amber-700 dark:bg-amber-500/20 dark:text-amber-300`,
    verde: `${CHIP_BASE} bg-emerald-100 text-emerald-700 dark:bg-emerald-500/20 dark:text-emerald-300`,
    neutro: `${CHIP_BASE} bg-muted text-muted-foreground`,
};

const ICONO_TIPO: Record<string, Component> = {
    vacaciones: Palmtree,
    permiso_con_goce: Clock,
    permiso_sin_goce: Clock,
    permiso_tiempo: Clock,
    salida_temprano: LogOut,
    llegada_tarde: AlarmClock,
    incapacidad: HeartPulse,
    constancia_laboral: FileText,
    actualizacion_datos: UserPen,
    actualizacion_bancaria: Landmark,
    reposicion_documental: FileText,
    prestamo: Wallet,
    baja_colaborador: UserMinus,
    permiso_especial_cumpleanos: Cake,
    permiso_especial_paternidad: Baby,
    permiso_especial_fallecimiento: Flower2,
};

function iconoTipo(tipo: string): Component {
    return ICONO_TIPO[tipo] ?? ClipboardList;
}

function diasEsperando(solicitud: SolicitudInternaItem): number {
    return Math.floor(
        (Date.now() - new Date(solicitud.created_at).getTime()) / 86_400_000,
    );
}

const columnas = reactive<Record<string, SolicitudInternaItem[]>>(
    Object.fromEntries(COLUMNAS.map((c) => [c.estado, []])),
);

function construirColumnas(lista: SolicitudInternaItem[]) {
    for (const c of COLUMNAS) {
        columnas[c.estado] = lista.filter((s) => s.estado === c.estado);
    }
}

construirColumnas(props.solicitudes);
watch(() => props.solicitudes, construirColumnas);

function etiquetaTipo(tipo: string): string {
    return props.tipos.find((t) => t.value === tipo)?.label ?? tipo;
}

function etiquetaColumna(estado: string): string {
    return COLUMNAS.find((c) => c.estado === estado)?.titulo ?? estado;
}

function fechaCorta(fecha: string): string {
    return new Date(fecha).toLocaleDateString('es-MX', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

function tieneFormatoGenerado(solicitud: SolicitudInternaItem): boolean {
    return (solicitud.documentos_generados?.length ?? 0) > 0;
}

function faltaFirma(solicitud: SolicitudInternaItem): boolean {
    const generados = solicitud.documentos_generados ?? [];

    return (
        generados.length > 0 && !generados.some((d) => d.status === 'firmado')
    );
}

// Una baja de colaborador no puede aprobarse sin evidencia adjunta ni
// finiquito revisado (docs/SOLICITUDES_UNIFICADAS.md): el tablero lo marca
// desde antes de intentar mover la tarjeta a "Aprobadas".
function sinEvidencia(solicitud: SolicitudInternaItem): boolean {
    return (
        solicitud.tipo === 'baja_colaborador' &&
        (solicitud.documentos_count ?? 0) === 0
    );
}

function finiquitoRevisado(solicitud: SolicitudInternaItem): boolean {
    const estado = solicitud.finiquitoCalculo?.estado;

    return (
        estado === 'revisado' || estado === 'aprobado' || estado === 'firmado'
    );
}

// Prioridad derivada del tiempo real de espera (no un campo inventado):
// una solicitud abierta (aun accionable) con mas de 5 dias desde su creacion
// se marca como urgente para que RH la note en el tablero.
const ESTADOS_ABIERTOS = ['enviada', 'en_revision', 'requiere_correccion'];

function esUrgente(solicitud: SolicitudInternaItem): boolean {
    if (!ESTADOS_ABIERTOS.includes(solicitud.estado)) {
        return false;
    }

    const dias =
        (Date.now() - new Date(solicitud.created_at).getTime()) / 86_400_000;

    return dias > 5;
}

// --- Drag and drop: confirmación + comentario obligatorio en rechazo/corrección ---
type MovimientoPendiente = {
    solicitud: SolicitudInternaItem;
    estadoOrigen: string;
    estadoDestino: string;
};

const movimientoPendiente = ref<MovimientoPendiente | null>(null);
const comentarioMovimiento = ref('');
const dialogMovimientoAbierto = ref(false);

const {
    processing: enviandoMovimiento,
    onStart: onStartDragBase,
    onEnd: onEndDragBase,
    restaurarCanonico,
    alSiguienteFrameLibre,
} = useKanbanTransition();

// El id+columna de origen de la tarjeta se capturan al INICIAR el arrastre
// (dataset del propio DOM, ver `data-kanban-id`/`data-estado` en la
// plantilla) en vez de confiar solo en `evento.data`/`evento.from` al
// soltar: para entonces `columnas[estado]` ya pudo mutar por el propio
// v-model de vue-draggable-plus.
const dragOrigenId = ref<number | null>(null);
const dragOrigenEstado = ref<string | null>(null);

const tableroBloqueado = computed(
    () => dialogMovimientoAbierto.value || enviandoMovimiento.value,
);

const requiereComentarioObligatorio = computed(
    () =>
        movimientoPendiente.value?.estadoDestino === 'rechazada' ||
        movimientoPendiente.value?.estadoDestino === 'requiere_correccion',
);

function onStartDrag(evento: DraggableEvent<SolicitudInternaItem>) {
    onStartDragBase();

    const idDataset = evento.item?.dataset.kanbanId;
    dragOrigenId.value = idDataset ? Number(idDataset) : null;
    dragOrigenEstado.value = evento.from?.dataset.estado ?? null;
}

function onEndDrag(evento: DraggableEvent<SolicitudInternaItem>) {
    onEndDragBase();

    const id =
        dragOrigenId.value ?? Number(evento.item?.dataset.kanbanId ?? NaN);
    const estadoOrigen = dragOrigenEstado.value ?? evento.from?.dataset.estado;
    const estadoDestino = evento.to?.dataset.estado;
    const solicitud = props.solicitudes.find((s) => s.id === id);

    dragOrigenId.value = null;
    dragOrigenEstado.value = null;

    if (
        !solicitud ||
        !estadoOrigen ||
        !estadoDestino ||
        estadoOrigen === estadoDestino
    ) {
        return;
    }

    // No se reconstruye el tablero aquí: dejar el drop como quedó (el
    // v-model de vue-draggable-plus ya lo reflejó) y solo forzar el estado
    // canónico si el usuario cancela o el servidor rechaza el cambio —
    // momentos desacoplados del gesto de arrastre (ver useKanbanTransition).
    //
    // Montar el Dialog se difiere a alSiguienteFrameLibre(): hacerlo
    // síncrono aquí (dentro del propio @end) seguía dando freeze en
    // reproducción real — Sortable todavía limpia sus referencias internas
    // justo después de este callback (ver useKanbanTransition).
    alSiguienteFrameLibre(() => {
        movimientoPendiente.value = { solicitud, estadoOrigen, estadoDestino };
        comentarioMovimiento.value = '';
        dialogMovimientoAbierto.value = true;
    });
}

function cancelarMovimiento() {
    restaurarCanonico(() => construirColumnas(props.solicitudes));
    cerrarDialogMovimiento();
}

function cerrarDialogMovimiento() {
    dialogMovimientoAbierto.value = false;
    movimientoPendiente.value = null;
    comentarioMovimiento.value = '';
}

function confirmarMovimiento() {
    const mov = movimientoPendiente.value;

    if (!mov) {
        return;
    }

    if (
        requiereComentarioObligatorio.value &&
        !comentarioMovimiento.value.trim()
    ) {
        mostrarError(
            'Agrega un comentario para mover la solicitud a este estado.',
        );

        return;
    }

    if (
        mov.estadoDestino === 'aprobada' &&
        mov.solicitud.tipo === 'baja_colaborador'
    ) {
        if (sinEvidencia(mov.solicitud)) {
            mostrarError(
                'Falta evidencia de baja: adjúntala desde el detalle antes de aprobar.',
            );

            return;
        }

        if (
            mov.solicitud.finiquitoCalculo &&
            !finiquitoRevisado(mov.solicitud)
        ) {
            mostrarError(
                'Falta revisar el finiquito antes de aprobar esta baja.',
            );

            return;
        }
    }

    enviandoMovimiento.value = true;

    router.patch(
        actualizarEstado.url(mov.solicitud.id),
        {
            estado: mov.estadoDestino,
            comentario: comentarioMovimiento.value || undefined,
            motivo_rechazo:
                mov.estadoDestino === 'rechazada'
                    ? comentarioMovimiento.value
                    : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                mostrarExito(
                    `Solicitud movida a ${etiquetaColumna(mov.estadoDestino)}.`,
                );
                cerrarDialogMovimiento();
            },
            onError: () => {
                mostrarError(
                    'No se pudo mover la solicitud. Verifica el permiso o el comentario.',
                );
                restaurarCanonico(() => construirColumnas(props.solicitudes));
                cerrarDialogMovimiento();
            },
            onFinish: () => {
                enviandoMovimiento.value = false;
            },
        },
    );
}
</script>

<template>
    <Head title="Solicitudes internas" />

    <div class="flex flex-col gap-3 p-3 sm:p-4">
        <Alert
            v-if="solicitudesResumen.total > solicitudesResumen.mostradas"
            data-tour="solicitudes-limite"
            variant="warning"
        >
            <AlertTriangle class="size-4" />
            <AlertTitle
                >Mostrando {{ solicitudesResumen.mostradas }} de
                {{ solicitudesResumen.total }} solicitudes activas</AlertTitle
            >
            <AlertDescription>
                El tablero tiene un límite de
                {{ solicitudesResumen.limite }} tarjetas para mantenerse ágil.
                Usa los filtros (sucursal, tipo, responsable) para acotar y ver
                el resto — ninguna solicitud se pierde, solo no se muestra aquí
                todavía.
            </AlertDescription>
        </Alert>

        <div
            data-tour="solicitudes-filtros"
            class="flex flex-wrap items-center gap-2"
        >
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por folio o motivo..."
                @update:model-value="
                    (v) => {
                        filtros.busqueda = v;
                        aplicarConDebounce();
                    }
                "
            />

            <Select
                :model-value="filtros.tipo"
                @update:model-value="
                    (v) => {
                        filtros.tipo = String(v ?? '');
                        aplicar();
                    }
                "
            >
                <SelectTrigger class="w-52"
                    ><SelectValue placeholder="Todos los tipos"
                /></SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="tipo in tipos"
                        :key="tipo.value"
                        :value="tipo.value"
                        >{{ tipo.label }}</SelectItem
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

            <CrudFilterSheet
                titulo="Más filtros"
                descripcion="Empresa, departamento, puesto, responsable y fecha de registro."
                :contador-activos="
                    [
                        filtros.empresa_id,
                        filtros.departamento_id,
                        filtros.puesto_id,
                        filtros.revisado_por,
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
                    <Label>Empresa</Label>
                    <Select
                        :model-value="filtros.empresa_id"
                        @update:model-value="
                            (v) => (filtros.empresa_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todas"
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
                </div>

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
                    <Label>Puesto</Label>
                    <Select
                        :model-value="filtros.puesto_id"
                        @update:model-value="
                            (v) => (filtros.puesto_id = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
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
                </div>

                <div class="grid gap-2">
                    <Label>Responsable RH</Label>
                    <Select
                        :model-value="filtros.revisado_por"
                        @update:model-value="
                            (v) => (filtros.revisado_por = String(v ?? ''))
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
                        <Label>Registrada desde</Label>
                        <DatePicker v-model="filtros.fecha_inicio" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Registrada hasta</Label>
                        <DatePicker v-model="filtros.fecha_fin" />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
            <div
                data-tour="solicitudes-exportar"
                class="ml-auto flex items-center gap-2"
            >
                <CrudExportButtons
                    :url-excel="urlExportar(exportarExcel)"
                    :url-pdf="urlExportar(exportarPdf)"
                />
            </div>
        </div>

        <!-- Resumen visual por estado -->
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
            <div
                v-for="columna in COLUMNAS"
                :key="`resumen-${columna.estado}`"
                class="flex items-center gap-3 rounded-2xl border border-border/60 bg-card p-3.5 shadow-sm"
            >
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-xl"
                    :class="columna.suave"
                >
                    <component :is="columna.icono" class="size-5" />
                </span>
                <div class="min-w-0">
                    <p class="text-2xl leading-none font-bold tabular-nums">
                        {{ columnas[columna.estado]?.length ?? 0 }}
                    </p>
                    <p class="mt-1 truncate text-sm text-muted-foreground">
                        {{ columna.titulo }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Tablero: horizontal real, sin comprimir columnas -->
        <div
            data-tour="solicitudes-tablero"
            class="flex w-full min-w-0 gap-4 overflow-x-auto pb-3"
        >
            <div
                v-for="columna in COLUMNAS"
                :key="columna.estado"
                class="flex w-[340px] min-w-[340px] shrink-0 flex-col overflow-hidden rounded-2xl border border-border/60 bg-muted/30"
            >
                <div
                    class="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3"
                    :class="columna.cabecera"
                >
                    <div class="flex min-w-0 items-center gap-2.5">
                        <span
                            class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                            :class="columna.suave"
                        >
                            <component :is="columna.icono" class="size-4" />
                        </span>
                        <p class="truncate text-base font-semibold">
                            {{ columna.titulo }}
                        </p>
                    </div>
                    <span
                        class="min-w-8 shrink-0 rounded-full px-2.5 py-0.5 text-center text-sm font-semibold tabular-nums"
                        :class="columna.suave"
                    >
                        {{ columnas[columna.estado]?.length ?? 0 }}
                    </span>
                </div>

                <VueDraggable
                    v-model="columnas[columna.estado]"
                    :data-estado="columna.estado"
                    class="flex min-h-32 flex-1 flex-col gap-3 p-3"
                    group="solicitudes-kanban"
                    :animation="150"
                    :disabled="tableroBloqueado"
                    handle=".kanban-drag-handle"
                    filter="a, button, input, textarea, select"
                    ghost-class="opacity-40"
                    @start="onStartDrag"
                    @end="onEndDrag"
                >
                    <div
                        v-if="(columnas[columna.estado]?.length ?? 0) === 0"
                        class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-border/80 p-6 text-center text-sm text-muted-foreground"
                    >
                        <Inbox class="size-6 opacity-50" />
                        Sin solicitudes aquí.
                    </div>

                    <div
                        v-for="solicitud in columnas[columna.estado]"
                        :key="solicitud.id"
                        :data-kanban-id="solicitud.id"
                        class="group relative flex flex-col gap-3 overflow-hidden rounded-xl border border-border/60 bg-card p-4 pl-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <span
                            class="absolute inset-y-0 left-0 w-1"
                            :class="columna.barra"
                            aria-hidden="true"
                        />

                        <div class="flex items-start justify-between gap-2">
                            <div class="flex min-w-0 items-center gap-3">
                                <ColaboradorAvatar
                                    :nombre="`${solicitud.usuario?.name ?? ''} ${solicitud.usuario?.apellidos ?? ''}`"
                                    :foto-url="solicitud.foto_url"
                                    tamano="md"
                                />
                                <div class="min-w-0">
                                    <p
                                        class="truncate text-[15px] leading-tight font-semibold"
                                    >
                                        {{ solicitud.usuario?.name }}
                                        {{ solicitud.usuario?.apellidos }}
                                    </p>
                                    <p
                                        class="mt-0.5 truncate text-xs text-muted-foreground"
                                    >
                                        {{ solicitud.folio }}
                                        <template
                                            v-if="
                                                solicitud.usuario?.colaborador
                                                    ?.puesto
                                            "
                                        >
                                            ·
                                            {{
                                                solicitud.usuario.colaborador
                                                    .puesto.nombre
                                            }}
                                        </template>
                                    </p>
                                </div>
                            </div>
                            <div class="flex shrink-0 items-center gap-0.5">
                                <Link
                                    :href="show.url(solicitud.id)"
                                    class="rounded-lg p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground"
                                    title="Abrir detalle"
                                >
                                    <ArrowUpRight class="size-4" />
                                </Link>
                                <span
                                    class="kanban-drag-handle flex size-7 cursor-grab items-center justify-center rounded-lg text-muted-foreground hover:bg-accent hover:text-foreground active:cursor-grabbing"
                                    title="Arrastrar para mover"
                                >
                                    <GripVertical class="size-4" />
                                </span>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <span
                                class="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2.5 py-1 text-xs font-medium text-primary"
                            >
                                <component
                                    :is="iconoTipo(solicitud.tipo)"
                                    class="size-3.5"
                                />
                                {{ etiquetaTipo(solicitud.tipo) }}
                            </span>
                            <span
                                v-if="solicitud.tipo === 'baja_colaborador'"
                                :class="CHIP.violeta"
                            >
                                <UserMinus class="size-3.5" /> Baja
                            </span>
                            <span
                                v-if="esUrgente(solicitud)"
                                :class="CHIP.naranja"
                            >
                                <AlarmClock class="size-3.5" />
                                {{ diasEsperando(solicitud) }} días esperando
                            </span>
                            <span
                                v-if="sinEvidencia(solicitud)"
                                :class="CHIP.ambar"
                            >
                                <ShieldAlert class="size-3.5" /> Sin evidencia
                            </span>
                            <span
                                v-if="
                                    solicitud.tipo === 'baja_colaborador' &&
                                    solicitud.finiquitoCalculo
                                "
                                :class="
                                    finiquitoRevisado(solicitud)
                                        ? CHIP.verde
                                        : CHIP.ambar
                                "
                            >
                                <ShieldCheck class="size-3.5" />
                                {{
                                    finiquitoRevisado(solicitud)
                                        ? 'Finiquito revisado'
                                        : 'Finiquito pendiente'
                                }}
                            </span>
                        </div>

                        <p
                            v-if="solicitud.motivo"
                            class="line-clamp-3 text-sm leading-relaxed text-foreground/80"
                        >
                            {{ solicitud.motivo }}
                        </p>

                        <div
                            v-if="
                                (solicitud.documentos_count ?? 0) > 0 ||
                                tieneFormatoGenerado(solicitud)
                            "
                            class="flex flex-wrap items-center gap-1.5"
                        >
                            <span
                                v-if="(solicitud.documentos_count ?? 0) > 0"
                                :class="CHIP.neutro"
                            >
                                <Paperclip class="size-3.5" />
                                {{ solicitud.documentos_count }}
                                {{
                                    solicitud.documentos_count === 1
                                        ? 'adjunto'
                                        : 'adjuntos'
                                }}
                            </span>
                            <span
                                v-if="tieneFormatoGenerado(solicitud)"
                                :class="CHIP.neutro"
                            >
                                <FileCheck2 class="size-3.5" /> Formato
                            </span>
                            <span
                                v-if="faltaFirma(solicitud)"
                                :class="CHIP.ambar"
                            >
                                <PenLine class="size-3.5" /> Falta firma
                            </span>
                        </div>

                        <div
                            class="flex items-center justify-between gap-2 border-t border-border/60 pt-3 text-xs text-muted-foreground"
                        >
                            <span
                                class="inline-flex min-w-0 items-center gap-1"
                            >
                                <MapPin class="size-3.5 shrink-0" />
                                <span class="truncate">{{
                                    solicitud.sucursal?.nombre ?? 'Sin sucursal'
                                }}</span>
                            </span>
                            <span
                                class="inline-flex shrink-0 items-center gap-1"
                            >
                                <CalendarDays class="size-3.5" />
                                {{ fechaCorta(solicitud.created_at) }}
                            </span>
                        </div>
                    </div>
                </VueDraggable>
            </div>
        </div>
    </div>

    <!-- Dialog: confirmar movimiento del tablero -->
    <Dialog
        :open="dialogMovimientoAbierto"
        @update:open="(v) => !v && cancelarMovimiento()"
    >
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Mover solicitud</DialogTitle>
                <DialogDescription v-if="movimientoPendiente">
                    {{ movimientoPendiente.solicitud.folio }} pasará de
                    <strong>{{
                        etiquetaColumna(movimientoPendiente.estadoOrigen)
                    }}</strong>
                    a
                    <strong>{{
                        etiquetaColumna(movimientoPendiente.estadoDestino)
                    }}</strong
                    >.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label>
                    Comentario
                    <span
                        v-if="requiereComentarioObligatorio"
                        class="text-destructive"
                        >*</span
                    >
                </Label>
                <Textarea
                    v-model="comentarioMovimiento"
                    :placeholder="
                        requiereComentarioObligatorio
                            ? 'Explica el motivo (obligatorio para este cambio de estado)...'
                            : 'Comentario opcional para el historial...'
                    "
                    rows="3"
                />
            </div>

            <DialogFooter>
                <Button
                    variant="secondary"
                    :disabled="enviandoMovimiento"
                    @click="cancelarMovimiento"
                >
                    Cancelar
                </Button>
                <Button
                    :disabled="enviandoMovimiento"
                    @click="confirmarMovimiento"
                >
                    <Spinner v-if="enviandoMovimiento" />
                    Confirmar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
