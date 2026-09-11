<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    FileCheck2,
    FileSpreadsheet,
    FileText,
    KanbanSquare,
    Paperclip,
    PenLine,
    UserX,
} from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import type { DraggableEvent } from 'vue-draggable-plus';
import { VueDraggable } from 'vue-draggable-plus';
import CrudFilterSheet from '@/components/DataTable/CrudFilterSheet.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
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
import { useInitials } from '@/composables/useInitials';
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
const { getInitials } = useInitials();

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
type ColumnaDefinicion = { estado: string; titulo: string; acento: string };

const COLUMNAS: ColumnaDefinicion[] = [
    { estado: 'enviada', titulo: 'Pendientes / Enviadas', acento: 'border-t-[var(--info)]' },
    { estado: 'en_revision', titulo: 'En revisión', acento: 'border-t-[var(--warning)]' },
    { estado: 'requiere_correccion', titulo: 'Requiere corrección', acento: 'border-t-[var(--warning)]' },
    { estado: 'aprobada', titulo: 'Aprobadas', acento: 'border-t-[var(--success)]' },
    { estado: 'rechazada', titulo: 'Rechazadas', acento: 'border-t-destructive' },
    { estado: 'cerrada', titulo: 'Cerradas', acento: 'border-t-muted-foreground' },
];

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

    return generados.length > 0 && !generados.some((d) => d.status === 'firmado');
}

// Prioridad derivada del tiempo real de espera (no un campo inventado):
// una solicitud abierta (aun accionable) con mas de 5 dias desde su creacion
// se marca como urgente para que RH la note en el tablero.
const ESTADOS_ABIERTOS = ['enviada', 'en_revision', 'requiere_correccion'];

function esUrgente(solicitud: SolicitudInternaItem): boolean {
    if (!ESTADOS_ABIERTOS.includes(solicitud.estado)) {
        return false;
    }

    const dias = (Date.now() - new Date(solicitud.created_at).getTime()) / 86_400_000;

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
const enviandoMovimiento = ref(false);

const requiereComentarioObligatorio = computed(
    () =>
        movimientoPendiente.value?.estadoDestino === 'rechazada' ||
        movimientoPendiente.value?.estadoDestino === 'requiere_correccion',
);

function revertirMovimiento(mov: MovimientoPendiente) {
    const destino = columnas[mov.estadoDestino];
    const idx = destino.findIndex((s) => s.id === mov.solicitud.id);

    if (idx !== -1) {
        destino.splice(idx, 1);
    }

    columnas[mov.estadoOrigen].push(mov.solicitud);
}

function onAdd(estadoDestino: string, evento: DraggableEvent<SolicitudInternaItem>) {
    const solicitud = evento.data;

    if (!solicitud || solicitud.estado === estadoDestino) {
        return;
    }

    movimientoPendiente.value = {
        solicitud,
        estadoOrigen: solicitud.estado,
        estadoDestino,
    };
    comentarioMovimiento.value = '';
    dialogMovimientoAbierto.value = true;
}

function cancelarMovimiento() {
    if (movimientoPendiente.value) {
        revertirMovimiento(movimientoPendiente.value);
    }

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

    if (requiereComentarioObligatorio.value && !comentarioMovimiento.value.trim()) {
        mostrarError('Agrega un comentario para mover la solicitud a este estado.');

        return;
    }

    enviandoMovimiento.value = true;

    router.patch(
        actualizarEstado.url(mov.solicitud.id),
        {
            estado: mov.estadoDestino,
            comentario: comentarioMovimiento.value || undefined,
            motivo_rechazo: mov.estadoDestino === 'rechazada' ? comentarioMovimiento.value : undefined,
        },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                mov.solicitud.estado = mov.estadoDestino;
                mostrarExito(`Solicitud movida a ${etiquetaColumna(mov.estadoDestino)}.`);
                cerrarDialogMovimiento();
            },
            onError: () => {
                revertirMovimiento(mov);
                mostrarError('No se pudo mover la solicitud. Verifica el permiso o el comentario.');
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

    <div class="flex flex-col gap-4 p-4">
        <CrudPageHeader
            titulo="Solicitudes internas"
            descripcion="Tablero de revisión: arrastra una tarjeta entre columnas para cambiar su estado."
            :icono="KanbanSquare"
        >
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarExcel)">
                    <FileSpreadsheet class="size-4" />
                    Excel
                </a>
            </Button>
            <Button as-child variant="outline" size="sm">
                <a :href="urlExportar(exportarPdf)">
                    <FileText class="size-4" />
                    PDF
                </a>
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2">
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
                        <Input
                            type="date"
                            :model-value="filtros.fecha_inicio"
                            @update:model-value="
                                (v) => (filtros.fecha_inicio = String(v ?? ''))
                            "
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label>Registrada hasta</Label>
                        <Input
                            type="date"
                            :model-value="filtros.fecha_fin"
                            @update:model-value="
                                (v) => (filtros.fecha_fin = String(v ?? ''))
                            "
                        />
                    </div>
                </div>
            </CrudFilterSheet>

            <Button variant="ghost" size="sm" @click="limpiar">
                Limpiar filtros
            </Button>
        </div>

        <!-- Tablero -->
        <div class="grid grid-cols-1 gap-3 overflow-x-auto pb-2 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
            <div
                v-for="columna in COLUMNAS"
                :key="columna.estado"
                class="flex min-w-0 flex-col rounded-xl border border-t-4 bg-muted/20"
                :class="columna.acento"
            >
                <div class="flex items-center justify-between gap-2 px-3 py-2.5">
                    <p class="text-sm font-semibold">{{ columna.titulo }}</p>
                    <Badge variant="secondary" class="shrink-0">
                        {{ columnas[columna.estado]?.length ?? 0 }}
                    </Badge>
                </div>

                <VueDraggable
                    v-model="columnas[columna.estado]"
                    class="flex min-h-24 flex-1 flex-col gap-2 px-2 pb-2"
                    group="solicitudes-kanban"
                    :animation="150"
                    ghost-class="opacity-40"
                    @add="(e) => onAdd(columna.estado, e)"
                >
                    <p
                        v-if="(columnas[columna.estado]?.length ?? 0) === 0"
                        class="rounded-lg border border-dashed p-4 text-center text-xs text-muted-foreground"
                    >
                        Sin solicitudes aquí.
                    </p>

                    <div
                        v-for="solicitud in columnas[columna.estado]"
                        :key="solicitud.id"
                        class="flex cursor-grab flex-col gap-2 rounded-xl border border-border/60 bg-card p-3 text-sm shadow-sm active:cursor-grabbing"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <Avatar class="size-7 shrink-0">
                                    <AvatarFallback class="text-[10px]">
                                        {{ getInitials(`${solicitud.usuario?.name ?? ''} ${solicitud.usuario?.apellidos ?? ''}`) }}
                                    </AvatarFallback>
                                </Avatar>
                                <div class="min-w-0">
                                    <p class="truncate text-xs font-semibold">
                                        {{ solicitud.usuario?.name }} {{ solicitud.usuario?.apellidos }}
                                    </p>
                                    <p class="text-[11px] text-muted-foreground">{{ solicitud.folio }}</p>
                                </div>
                            </div>
                            <Link
                                :href="show.url(solicitud.id)"
                                class="shrink-0 rounded-md p-1 text-muted-foreground hover:bg-accent hover:text-foreground"
                                title="Abrir detalle"
                            >
                                <FileText class="size-3.5" />
                            </Link>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <Badge variant="outline" class="text-[10px] capitalize">
                                {{ etiquetaTipo(solicitud.tipo) }}
                            </Badge>
                            <Badge v-if="solicitud.tipo === 'baja_colaborador'" variant="destructive" class="gap-1 text-[10px]">
                                <UserX class="size-3" /> Baja
                            </Badge>
                            <Badge v-if="esUrgente(solicitud)" variant="warning" class="gap-1 text-[10px]">
                                <AlertTriangle class="size-3" /> Urgente
                            </Badge>
                        </div>

                        <p class="line-clamp-2 text-xs text-muted-foreground">
                            {{ solicitud.motivo }}
                        </p>

                        <div class="flex items-center justify-between text-[11px] text-muted-foreground">
                            <span>{{ solicitud.sucursal?.nombre ?? '—' }}</span>
                            <span>{{ fechaCorta(solicitud.created_at) }}</span>
                        </div>

                        <div v-if="(solicitud.documentos_count ?? 0) > 0 || tieneFormatoGenerado(solicitud)" class="flex flex-wrap items-center gap-1.5">
                            <span v-if="(solicitud.documentos_count ?? 0) > 0" class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground">
                                <Paperclip class="size-3" /> {{ solicitud.documentos_count }}
                            </span>
                            <span v-if="tieneFormatoGenerado(solicitud)" class="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-[10px] text-muted-foreground">
                                <FileCheck2 class="size-3" /> Formato
                            </span>
                            <span v-if="faltaFirma(solicitud)" class="inline-flex items-center gap-1 rounded-full bg-[var(--warning)]/10 px-2 py-0.5 text-[10px] text-[var(--warning)]">
                                <PenLine class="size-3" /> Falta firma
                            </span>
                        </div>
                    </div>
                </VueDraggable>
            </div>
        </div>
    </div>

    <!-- Dialog: confirmar movimiento del tablero -->
    <Dialog :open="dialogMovimientoAbierto" @update:open="(v) => !v && cancelarMovimiento()">
        <DialogContent class="sm:max-w-md">
            <DialogHeader>
                <DialogTitle>Mover solicitud</DialogTitle>
                <DialogDescription v-if="movimientoPendiente">
                    {{ movimientoPendiente.solicitud.folio }} pasará de
                    <strong>{{ etiquetaColumna(movimientoPendiente.estadoOrigen) }}</strong>
                    a <strong>{{ etiquetaColumna(movimientoPendiente.estadoDestino) }}</strong>.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-2">
                <Label>
                    Comentario
                    <span v-if="requiereComentarioObligatorio" class="text-destructive">*</span>
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
                <Button variant="secondary" :disabled="enviandoMovimiento" @click="cancelarMovimiento">
                    Cancelar
                </Button>
                <Button :disabled="enviandoMovimiento" @click="confirmarMovimiento">
                    <Spinner v-if="enviandoMovimiento" />
                    Confirmar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
