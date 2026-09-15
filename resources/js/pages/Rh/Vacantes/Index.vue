<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Briefcase,
    CheckCircle2,
    GripVertical,
    ListChecks,
    Plus,
    Sparkles,
    Trash2,
    UserCheck,
    Users,
    Wand2,
    XCircle,
} from '@lucide/vue';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import type { DraggableEvent } from 'vue-draggable-plus';
import { VueDraggable } from 'vue-draggable-plus';
import DatePicker from '@/components/Common/DatePicker.vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import CrudExportButtons from '@/components/DataTable/CrudExportButtons.vue';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import PeopleConfirmDialog from '@/components/people/PeopleConfirmDialog.vue';
import CubrirVacanteDialog from '@/components/Rh/CubrirVacanteDialog.vue';
import VacanteFormDialog from '@/components/Rh/VacanteFormDialog.vue';
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
import { useFiltros } from '@/composables/useFiltros';
import { useKanbanTransition } from '@/composables/useKanbanTransition';
import { dashboard } from '@/routes';
import {
    destroy,
    estado as estadoUrl,
    exportarExcel,
    exportarPdf,
    index,
} from '@/routes/rh/vacantes';
import type {
    OpcionesReclutamiento,
    VacanteItem,
    VacantesKpis,
} from '@/types';

const props = defineProps<{
    vacantes: VacanteItem[];
    kpis: VacantesKpis;
    filtros: {
        empresa_id?: string;
        sucursal_id?: string;
        departamento_id?: string;
        puesto_id?: string;
        responsable_rh_id?: string;
        estado?: string;
        busqueda?: string;
        fecha_inicio?: string;
        fecha_fin?: string;
    };
    opciones: OpcionesReclutamiento;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Vacantes', href: index.url() },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    index.url(),
    {
        empresa_id: props.filtros.empresa_id ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        departamento_id: props.filtros.departamento_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        responsable_rh_id: props.filtros.responsable_rh_id ?? '',
        estado: props.filtros.estado ?? '',
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
const { confirmarEliminacion, mostrarError, mostrarExito } = useAlertas();

const COLUMNAS = [
    { estado: 'abierta', titulo: 'Abierta' },
    { estado: 'en_reclutamiento', titulo: 'En reclutamiento' },
    { estado: 'con_candidatos', titulo: 'Con candidatos' },
    { estado: 'en_revision', titulo: 'En revisión' },
    { estado: 'cubierta', titulo: 'Cubierta' },
    { estado: 'cancelada', titulo: 'Cancelada' },
];

const columnas = reactive<Record<string, VacanteItem[]>>(
    Object.fromEntries(COLUMNAS.map((c) => [c.estado, []])),
);

function construirColumnas(lista: VacanteItem[]) {
    for (const c of COLUMNAS) {
        columnas[c.estado] = lista.filter((v) => v.estado === c.estado);
    }
}

construirColumnas(props.vacantes);
watch(() => props.vacantes, construirColumnas);

const tarjetasKpi = computed(() => [
    {
        etiqueta: 'Vacantes abiertas',
        valor: props.kpis.vacantes_abiertas,
        icono: Briefcase,
        colorClase: 'bg-[var(--brand-primary)]/10 text-[var(--brand-primary)]',
    },
    {
        etiqueta: 'Plazas disponibles',
        valor: props.kpis.plazas_disponibles,
        icono: Users,
        colorClase: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    {
        etiqueta: 'Vacantes automáticas',
        valor: props.kpis.vacantes_automaticas,
        icono: Wand2,
        colorClase: 'bg-sky-500/10 text-sky-600 dark:text-sky-400',
    },
    {
        etiqueta: 'Vacantes manuales',
        valor: props.kpis.vacantes_manuales,
        icono: ListChecks,
        colorClase: 'bg-muted text-muted-foreground',
    },
    {
        etiqueta: 'En reclutamiento',
        valor: props.kpis.en_reclutamiento,
        icono: Sparkles,
        colorClase: 'bg-amber-500/10 text-amber-600 dark:text-amber-400',
    },
    {
        etiqueta: 'Cubiertas este mes',
        valor: props.kpis.cubiertas_este_mes,
        icono: CheckCircle2,
        colorClase: 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
    },
    {
        etiqueta: 'Canceladas',
        valor: props.kpis.canceladas,
        icono: XCircle,
        colorClase: 'bg-destructive/10 text-destructive',
    },
]);

const dialogoAbierto = ref(false);
const seleccionada = ref<VacanteItem | null>(null);
const prefillCreacion = ref<{
    puesto_id?: number;
    departamento_id?: number;
    empresa_id?: number;
    sucursal_id?: number;
    motivo?: string;
} | null>(null);

function abrirCrear() {
    seleccionada.value = null;
    dialogoAbierto.value = true;
}

// Llegada desde "Crear vacante para este puesto" en Jerarquía de puestos:
// ?crear=1&puesto_id=..&departamento_id=.. abre el diálogo precargado.
onMounted(() => {
    const query = new URLSearchParams(window.location.search);

    if (query.get('crear') !== '1') {
        return;
    }

    prefillCreacion.value = {
        puesto_id: props.filtros.puesto_id
            ? Number(props.filtros.puesto_id)
            : undefined,
        departamento_id: props.filtros.departamento_id
            ? Number(props.filtros.departamento_id)
            : undefined,
        empresa_id: props.filtros.empresa_id
            ? Number(props.filtros.empresa_id)
            : undefined,
        motivo: 'nueva_posicion',
    };
    abrirCrear();
});

function abrirEditar(vacante: VacanteItem) {
    seleccionada.value = vacante;
    dialogoAbierto.value = true;
}

const dialogoCubrirAbierto = ref(false);
const vacanteACubrir = ref<VacanteItem | null>(null);

function abrirCubrir(vacante: VacanteItem) {
    vacanteACubrir.value = vacante;
    dialogoCubrirAbierto.value = true;
}

async function eliminar(vacante: VacanteItem) {
    const confirmado = await confirmarEliminacion(
        `la vacante de «${vacante.puesto?.nombre ?? 'este puesto'}»`,
    );

    if (!confirmado) {
        return;
    }

    router.delete(destroy.url(vacante.id), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('Vacante eliminada.'),
        onError: () => mostrarError('No fue posible eliminar la vacante.'),
    });
}

// --- Drag and drop: VueDraggable (misma librería y patrón que el tablero de
// Solicitudes) con confirmación/reglas SOLO después de que Sortable termina
// (@end, nunca @add — ver useKanbanTransition). "Cubierta" nunca se asigna
// soltando una tarjeta: abre CubrirVacanteDialog en su lugar (cobertura
// real), igual que el botón "Cubrir vacante" de la tarjeta. "Cancelada"
// pide motivo con PeopleConfirmDialog (no SweetAlert, para no abrir un
// segundo sistema de overlays). Cualquier otra transición pide una
// confirmación ligera antes de escribir nada — el tablero se restaura a
// `columnas` (fuente canónica: props.vacantes) de inmediato al soltar.
const {
    processing: enviandoTransicionVacante,
    onStart: onStartDragVacante,
    onEnd: onEndDragVacanteBase,
    asentarAntesDeConfirmar: asentarVacante,
} = useKanbanTransition();

type TransicionVacantePendiente = {
    vacante: VacanteItem;
    estadoOrigen: string;
    estadoDestino: string;
};

const transicionPendiente = ref<TransicionVacantePendiente | null>(null);
const dialogTransicionAbierto = ref(false);

const vacanteACancelar = ref<VacanteItem | null>(null);
const dialogCancelarAbierto = ref(false);
const motivoCancelacionTexto = ref('');

const tableroVacantesBloqueado = computed(
    () =>
        dialogTransicionAbierto.value ||
        dialogCancelarAbierto.value ||
        dialogoCubrirAbierto.value ||
        enviandoTransicionVacante.value,
);

function etiquetaEstadoVacante(estado: string): string {
    return COLUMNAS.find((c) => c.estado === estado)?.titulo ?? estado;
}

async function onEndDragVacante(evento: DraggableEvent<VacanteItem>) {
    onEndDragVacanteBase();

    const vacante = evento.data;
    const estadoOrigen = evento.from?.dataset.estado;
    const estadoDestino = evento.to?.dataset.estado;

    if (
        !vacante ||
        !estadoOrigen ||
        !estadoDestino ||
        estadoOrigen === estadoDestino
    ) {
        return;
    }

    await asentarVacante(() => construirColumnas(props.vacantes));

    if (estadoDestino === 'cubierta') {
        abrirCubrir(vacante);

        return;
    }

    if (estadoDestino === 'cancelada') {
        vacanteACancelar.value = vacante;
        motivoCancelacionTexto.value = '';
        dialogCancelarAbierto.value = true;

        return;
    }

    transicionPendiente.value = { vacante, estadoOrigen, estadoDestino };
    dialogTransicionAbierto.value = true;
}

function cerrarDialogTransicion() {
    dialogTransicionAbierto.value = false;
    transicionPendiente.value = null;
}

function confirmarTransicionVacante() {
    const mov = transicionPendiente.value;

    if (!mov) {
        return;
    }

    enviandoTransicionVacante.value = true;

    router.put(
        estadoUrl.url(mov.vacante.id),
        { estado: mov.estadoDestino },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () =>
                mostrarExito('Estado de la vacante actualizado.'),
            onError: () =>
                mostrarError(
                    'No se pudo mover la vacante. Verifica el permiso o la transición.',
                ),
            onFinish: () => {
                enviandoTransicionVacante.value = false;
                cerrarDialogTransicion();
            },
        },
    );
}

function cerrarDialogCancelar() {
    dialogCancelarAbierto.value = false;
    vacanteACancelar.value = null;
    motivoCancelacionTexto.value = '';
}

function confirmarCancelacionVacante() {
    const vacante = vacanteACancelar.value;

    if (!vacante || !motivoCancelacionTexto.value.trim()) {
        return;
    }

    enviandoTransicionVacante.value = true;

    router.put(
        estadoUrl.url(vacante.id),
        {
            estado: 'cancelada',
            motivo_cancelacion: motivoCancelacionTexto.value,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => mostrarExito('Vacante cancelada.'),
            onError: () =>
                mostrarError(
                    'No se pudo cancelar la vacante. Verifica el permiso.',
                ),
            onFinish: () => {
                enviandoTransicionVacante.value = false;
                cerrarDialogCancelar();
            },
        },
    );
}
</script>

<template>
    <Head title="Vacantes" />

    <div class="flex flex-col gap-6 p-4">
        <CrudPageHeader
            titulo="Vacantes y cobertura de plantilla"
            descripcion="Da seguimiento a las vacantes abiertas, sus plazas y su cobertura."
            :icono="Briefcase"
        >
            <CrudExportButtons
                :url-excel="urlExportar(exportarExcel)"
                :url-pdf="urlExportar(exportarPdf)"
            />
            <Button @click="abrirCrear">
                <Plus class="size-4" />
                Nueva vacante
            </Button>
        </CrudPageHeader>

        <div
            class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-7"
        >
            <MetricCard
                v-for="tarjeta in tarjetasKpi"
                :key="tarjeta.etiqueta"
                :etiqueta="tarjeta.etiqueta"
                :valor="tarjeta.valor"
                :icono="tarjeta.icono"
                :color-clase="tarjeta.colorClase"
            />
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <CrudSearchInput
                :model-value="filtros.busqueda"
                placeholder="Buscar por puesto o departamento..."
                @update:model-value="
                    (valor) => {
                        filtros.busqueda = valor;
                        aplicarConDebounce();
                    }
                "
            />

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
                :model-value="filtros.puesto_id"
                @update:model-value="
                    (v) => {
                        filtros.puesto_id = String(v ?? '');
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
                descripcion="Departamento, responsable y rango de fechas de apertura."
                :contador-activos="
                    [
                        filtros.departamento_id,
                        filtros.responsable_rh_id,
                        filtros.estado,
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

                <div class="grid gap-2">
                    <Label>Estado</Label>
                    <Select
                        :model-value="filtros.estado"
                        @update:model-value="
                            (v) => (filtros.estado = String(v ?? ''))
                        "
                    >
                        <SelectTrigger
                            ><SelectValue placeholder="Todos"
                        /></SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opcion in opciones.estados"
                                :key="opcion.value"
                                :value="opcion.value"
                                >{{ opcion.etiqueta }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div class="grid gap-2">
                        <Label>Apertura desde</Label>
                        <DatePicker v-model="filtros.fecha_inicio" />
                    </div>
                    <div class="grid gap-2">
                        <Label>Apertura hasta</Label>
                        <DatePicker v-model="filtros.fecha_fin" />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <div class="flex gap-4 overflow-x-auto pb-4">
            <div
                v-for="columna in COLUMNAS"
                :key="columna.estado"
                class="flex w-72 shrink-0 flex-col gap-3 rounded-2xl border border-border/60 bg-muted/20 p-3"
            >
                <div class="flex items-center justify-between px-1">
                    <h3 class="text-sm font-semibold">{{ columna.titulo }}</h3>
                    <Badge variant="outline">{{
                        columnas[columna.estado]?.length ?? 0
                    }}</Badge>
                </div>

                <VueDraggable
                    v-model="columnas[columna.estado]"
                    :data-estado="columna.estado"
                    class="flex min-h-16 flex-col gap-2"
                    group="vacantes-kanban"
                    :animation="150"
                    :disabled="tableroVacantesBloqueado"
                    handle=".kanban-drag-handle"
                    filter="a, button, input, textarea, select"
                    ghost-class="opacity-40"
                    @start="onStartDragVacante"
                    @end="onEndDragVacante"
                >
                    <div
                        v-for="vacante in columnas[columna.estado]"
                        :key="vacante.id"
                        role="button"
                        tabindex="0"
                        class="group flex flex-col gap-1 rounded-xl border border-border/60 bg-card p-3 text-left shadow-sm transition-colors hover:border-primary/40"
                        @click="abrirEditar(vacante)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <span class="flex min-w-0 items-center gap-1.5 text-sm font-medium">
                                <span
                                    class="kanban-drag-handle -m-1 flex size-6 shrink-0 cursor-grab items-center justify-center rounded text-muted-foreground hover:bg-accent hover:text-foreground active:cursor-grabbing"
                                    title="Arrastrar para mover"
                                    @click.stop
                                >
                                    <GripVertical class="size-3.5" />
                                </span>
                                <span class="truncate">{{
                                    vacante.puesto?.nombre ?? 'Sin puesto'
                                }}</span>
                            </span>
                            <div
                                class="flex shrink-0 items-center gap-1 opacity-100 transition-opacity md:opacity-0 md:group-hover:opacity-100"
                            >
                                <Button
                                    v-if="
                                        columna.estado !== 'cubierta' &&
                                        columna.estado !== 'cancelada'
                                    "
                                    variant="ghost"
                                    size="icon-xs"
                                    class="text-muted-foreground hover:bg-primary/10 hover:text-primary"
                                    title="Cubrir vacante"
                                    @click.stop="abrirCubrir(vacante)"
                                >
                                    <UserCheck class="size-3.5" />
                                </Button>
                                <Button
                                    v-if="!vacante.generada_automaticamente"
                                    variant="ghost"
                                    size="icon-xs"
                                    class="text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                                    title="Eliminar"
                                    @click.stop="eliminar(vacante)"
                                >
                                    <Trash2 class="size-3.5" />
                                </Button>
                            </div>
                        </div>
                        <span class="text-xs text-muted-foreground">{{
                            vacante.sucursal?.nombre ?? 'Sin sucursal'
                        }}</span>
                        <span
                            v-if="vacante.departamento"
                            class="text-xs text-muted-foreground"
                            >{{ vacante.departamento.nombre }}</span
                        >

                        <div class="mt-1 flex flex-wrap items-center gap-1.5">
                            <EstadoBadge
                                :estado="
                                    vacante.generada_automaticamente
                                        ? 'automatica'
                                        : 'manual'
                                "
                            />
                            <Badge
                                v-if="vacante.plazas_disponibles > 0"
                                variant="outline"
                                class="border-[var(--brand-primary)]/40 text-[var(--brand-primary)]"
                            >
                                {{ vacante.plazas_disponibles }} disponible{{
                                    vacante.plazas_disponibles === 1 ? '' : 's'
                                }}
                            </Badge>
                        </div>

                        <div
                            class="mt-1 grid grid-cols-3 gap-1 rounded-lg bg-muted/40 p-1.5 text-center text-[11px] text-muted-foreground"
                        >
                            <div>
                                <p class="font-semibold text-foreground">
                                    {{ vacante.plazas_requeridas }}
                                </p>
                                <p>Requeridas</p>
                            </div>
                            <div>
                                <p class="font-semibold text-foreground">
                                    {{ vacante.plazas_cubiertas }}
                                </p>
                                <p>Cubiertas</p>
                            </div>
                            <div>
                                <p class="font-semibold text-foreground">
                                    {{ vacante.plazas_disponibles }}
                                </p>
                                <p>Disponibles</p>
                            </div>
                        </div>

                        <div
                            v-if="vacante.plantilla_autorizada !== null"
                            class="mt-1 grid grid-cols-3 gap-1 rounded-lg bg-muted/40 p-1.5 text-center text-[11px] text-muted-foreground"
                        >
                            <div>
                                <p class="font-semibold text-foreground">
                                    {{ vacante.plantilla_autorizada }}
                                </p>
                                <p>Plantilla aut.</p>
                            </div>
                            <div>
                                <p class="font-semibold text-foreground">
                                    {{ vacante.plantilla_actual }}
                                </p>
                                <p>Plantilla act.</p>
                            </div>
                            <div>
                                <p class="font-semibold text-foreground">
                                    {{ vacante.faltantes_reales }}
                                </p>
                                <p>Faltantes</p>
                            </div>
                        </div>

                        <span
                            v-if="vacante.responsable_rh"
                            class="text-xs text-muted-foreground"
                        >
                            RH: {{ vacante.responsable_rh.name }}
                            {{ vacante.responsable_rh.apellidos }}
                        </span>

                        <p
                            v-if="vacante.motivo_cancelacion"
                            class="text-xs text-destructive"
                            :title="vacante.motivo_cancelacion"
                        >
                            Motivo: {{ vacante.motivo_cancelacion }}
                        </p>

                        <div
                            class="mt-1 flex items-center justify-between text-xs text-muted-foreground"
                        >
                            <span class="inline-flex items-center gap-1">
                                <Users class="size-3" />
                                {{ vacante.candidatos_count }}
                            </span>
                            <span>{{ vacante.fecha_apertura }}</span>
                        </div>
                    </div>

                    <p
                        v-if="!(columnas[columna.estado]?.length ?? 0)"
                        class="rounded-xl border border-dashed p-3 text-center text-xs text-muted-foreground"
                    >
                        Sin vacantes
                    </p>
                </VueDraggable>
            </div>
        </div>
    </div>

    <VacanteFormDialog
        v-if="dialogoAbierto"
        v-model:open="dialogoAbierto"
        :vacante="seleccionada"
        :opciones="opciones"
        :prefill="seleccionada ? null : prefillCreacion"
        :key="seleccionada?.id ?? 'nueva'"
    />

    <CubrirVacanteDialog
        v-if="dialogoCubrirAbierto && vacanteACubrir"
        v-model:open="dialogoCubrirAbierto"
        :vacante="vacanteACubrir"
        :opciones="opciones"
        :key="`cubrir-${vacanteACubrir.id}`"
    />

    <PeopleConfirmDialog
        :open="dialogTransicionAbierto"
        titulo="Mover vacante"
        :descripcion="
            transicionPendiente
                ? `¿Mover de ${etiquetaEstadoVacante(transicionPendiente.estadoOrigen)} a ${etiquetaEstadoVacante(transicionPendiente.estadoDestino)}?`
                : undefined
        "
        :cargando="enviandoTransicionVacante"
        texto-confirmar="Sí, mover"
        @update:open="(v) => (v ? null : cerrarDialogTransicion())"
        @confirm="confirmarTransicionVacante"
    />

    <PeopleConfirmDialog
        :open="dialogCancelarAbierto"
        titulo="¿Cancelar vacante?"
        descripcion="Indica por qué esta plaza ya no se va a cubrir."
        pedir-comentario
        comentario-label="Motivo de cancelación"
        comentario-placeholder="Ej. La ruta se dio de baja y ya no requiere cobertura."
        :comentario-model-value="motivoCancelacionTexto"
        destructivo
        :cargando="enviandoTransicionVacante"
        texto-confirmar="Sí, cancelar vacante"
        @update:open="(v) => (v ? null : cerrarDialogCancelar())"
        @update:comentario-model-value="(v) => (motivoCancelacionTexto = v)"
        @confirm="confirmarCancelacionVacante"
    />
</template>
