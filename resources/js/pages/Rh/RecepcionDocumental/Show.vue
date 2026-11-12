<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CircleCheck,
    Clock,
    FileDown,
    History,
    Lock,
    LockOpen,
    MoreHorizontal,
    PackageCheck,
    Paperclip,
    Percent,
    RefreshCw,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/Common/EmptyState.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudSearchInput from '@/components/DataTable/CrudSearchInput.vue';
import CrudStats from '@/components/DataTable/CrudStats.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import { Badge } from '@/components/ui/badge';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { parametrosPorPaginaActuales } from '@/composables/usePaginacion';
import { formatearFecha, formatearFechaHora } from '@/lib/fechas';
import { enviarJson, ErrorSolicitud, getJson, leerCookie } from '@/lib/http';
import { dashboard } from '@/routes';
import {
    cerrar as rutaCerrar,
    index,
    materializar,
    pdf as rutaPdf,
    reabrir as rutaReabrir,
    show,
} from '@/routes/rh/recepcion-documental';
import { show as verPdf } from '@/routes/rh/recepcion-documental/pdfs';
import {
    evidencia as rutaEvidencia,
    historial as rutaHistorial,
    marcar,
} from '@/routes/rh/recepcion-documental/renglones';
import { ver as verEvidencia } from '@/routes/rh/recepcion-documental/renglones/evidencia';
import type {
    AgrupacionesRecepcion,
    CorteRecepcion,
    EstadoCorteRecepcion,
    EstadoRecepcionDocumento,
    FiltrosRecepcion,
    HistorialRecepcion,
    MetricasRecepcion,
    OpcionesFiltrosRecepcion,
    PermisosRecepcion,
    RenglonRecepcion,
    VersionPdfRecepcion,
} from '@/types';

const props = defineProps<{
    corte: CorteRecepcion;
    metricasFiltradas: MetricasRecepcion;
    agrupaciones: AgrupacionesRecepcion;
    renglones: {
        data: RenglonRecepcion[];
        current_page: number;
        last_page: number;
        per_page: number;
        from: number | null;
        to: number | null;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    opciones: OpcionesFiltrosRecepcion;
    filtros: FiltrosRecepcion;
    permisos: PermisosRecepcion;
    pdfs: VersionPdfRecepcion[];
    historial: HistorialRecepcion[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recepción documental', href: index() },
            { title: 'Corte', href: '' },
        ],
    },
});

type RespuestaMarcar = {
    renglon: RenglonRecepcion;
    metricas: MetricasRecepcion;
    estado_corte: { value: EstadoCorteRecepcion; label: string };
};

// ───────────── Estado local (optimista) ─────────────
const filas = ref<RenglonRecepcion[]>([...props.renglones.data]);
const metricas = ref<MetricasRecepcion>({ ...props.corte.metricas });
const estadoCorte = ref({
    value: props.corte.estado,
    label: props.corte.estado_etiqueta,
});
const guardando = ref(new Set<number>());

watch(
    () => props.renglones.data,
    (nuevas) => (filas.value = [...nuevas]),
);
watch(
    () => props.corte,
    (c) => {
        metricas.value = { ...c.metricas };
        estadoCorte.value = { value: c.estado, label: c.estado_etiqueta };
    },
);

const cerrado = computed(() => estadoCorte.value.value === 'cerrado');

const estadisticas = computed(() => [
    {
        etiqueta: 'Esperados',
        valor: metricas.value.esperados,
        subvalor: `${metricas.value.no_aplica} no aplican`,
        icono: PackageCheck,
        tono: 'info' as const,
    },
    {
        etiqueta: 'Recibidos',
        valor: metricas.value.recibidos,
        icono: CircleCheck,
        tono: 'success' as const,
    },
    {
        etiqueta: 'Pendientes',
        valor: metricas.value.pendientes,
        subvalor: `${metricas.value.no_recibidos} no recibidos`,
        icono: Clock,
        tono: 'warning' as const,
    },
    {
        etiqueta: 'Con incidencia',
        valor: metricas.value.incidencias,
        icono: AlertTriangle,
        tono: 'danger' as const,
    },
    {
        etiqueta: 'Cumplimiento',
        valor: `${metricas.value.cumplimiento}%`,
        icono: Percent,
        tono: 'default' as const,
    },
]);

const VARIANTE_RENGLON: Record<
    EstadoRecepcionDocumento,
    'secondary' | 'success' | 'warning' | 'destructive' | 'outline'
> = {
    pendiente: 'secondary',
    recibido: 'success',
    recibido_con_observacion: 'warning',
    no_recibido: 'destructive',
    no_aplica: 'outline',
};

const VARIANTE_CORTE: Record<
    EstadoCorteRecepcion,
    'secondary' | 'info' | 'warning' | 'success' | 'outline'
> = {
    en_preparacion: 'secondary',
    abierto: 'info',
    con_pendientes: 'warning',
    completo: 'success',
    cerrado: 'outline',
};

const ETIQUETAS_ESTADO: Record<EstadoRecepcionDocumento, string> = {
    pendiente: 'Pendiente',
    recibido: 'Recibido',
    recibido_con_observacion: 'Recibido con observación',
    no_recibido: 'No recibido',
    no_aplica: 'No aplica',
};

function esRecibido(fila: RenglonRecepcion): boolean {
    return (
        fila.estado === 'recibido' || fila.estado === 'recibido_con_observacion'
    );
}

// ───────────── Filtros ─────────────
const f = reactive({
    sucursal_id: props.filtros.sin_sucursal
        ? 'sin'
        : props.filtros.sucursal_id !== null
          ? String(props.filtros.sucursal_id)
          : '',
    regla: props.filtros.regla ?? '',
    tipo_documento: props.filtros.tipo_documento ?? '',
    estado: props.filtros.estado ?? '',
    recibido: props.filtros.recibido ?? '',
    colaborador: props.filtros.colaborador ?? '',
});

const hayFiltros = computed(() =>
    Object.values(f).some((valor) => valor !== ''),
);

function parametrosFiltro(): Record<string, string> {
    return Object.fromEntries(
        Object.entries(f).filter(([, valor]) => valor !== ''),
    );
}

function filtrar() {
    router.get(
        show.url(props.corte.id),
        { ...parametrosPorPaginaActuales(), ...parametrosFiltro() },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(
    () => [f.sucursal_id, f.regla, f.tipo_documento, f.estado, f.recibido],
    filtrar,
);
watch(() => f.colaborador, useDebounceFn(filtrar, 300));

function limpiarFiltros() {
    Object.assign(f, {
        sucursal_id: '',
        regla: '',
        tipo_documento: '',
        estado: '',
        recibido: '',
        colaborador: '',
    });
}

function verSucursal(id: number | null) {
    f.sucursal_id = id === null ? 'sin' : String(id);
}

// ───────────── Agrupación SUCURSAL → evento → persona → documentos ─────────────
type Evento = {
    clave: string;
    titulo: string;
    descripcion: string | null;
    filas: RenglonRecepcion[];
};
type Bloque = {
    regla: string;
    etiqueta: string;
    orden: number;
    eventos: Evento[];
};
type Grupo = { sucursal: string; bloques: Bloque[] };

const grupos = computed<Grupo[]>(() => {
    const resultado: Grupo[] = [];

    for (const fila of filas.value) {
        let grupo = resultado.find((g) => g.sucursal === fila.sucursal.nombre);

        if (!grupo) {
            grupo = { sucursal: fila.sucursal.nombre, bloques: [] };
            resultado.push(grupo);
        }

        let bloque = grupo.bloques.find((b) => b.regla === fila.regla);

        if (!bloque) {
            bloque = {
                regla: fila.regla,
                etiqueta: fila.regla_etiqueta.toUpperCase(),
                orden: fila.regla_orden,
                eventos: [],
            };
            grupo.bloques.push(bloque);
        }

        const esNomina = fila.regla === 'recibos_nomina';
        const clave = esNomina
            ? `persona-${fila.colaborador.id ?? fila.colaborador.nombre}`
            : `${fila.origen.tipo}-${fila.origen.id}`;
        let evento = bloque.eventos.find((e) => e.clave === clave);

        if (!evento) {
            evento = {
                clave,
                titulo: [
                    fila.regla === 'listas_asistencia'
                        ? 'Lotes semanales de la sucursal'
                        : (fila.colaborador.nombre ?? 'Sin persona'),
                    fila.colaborador.numero_empleado
                        ? `No. ${fila.colaborador.numero_empleado}`
                        : null,
                    fila.colaborador.puesto,
                ]
                    .filter(Boolean)
                    .join(' · '),
                descripcion:
                    esNomina || fila.regla === 'listas_asistencia'
                        ? null
                        : fila.evento_descripcion,
                filas: [],
            };
            bloque.eventos.push(evento);
        }

        evento.filas.push(fila);
    }

    for (const grupo of resultado) {
        grupo.bloques.sort((a, b) => a.orden - b.orden);
    }

    return resultado;
});

const reglasSinEventos = computed(() =>
    props.agrupaciones.reglas.filter(
        (r) => r.activa && r.metricas.renglones === 0,
    ),
);

const textoVacioFiltrado = computed(() => {
    const regla = props.agrupaciones.reglas.find((r) => r.regla === f.regla);

    return regla && regla.metricas.renglones === 0
        ? regla.sin_eventos
        : 'No hay originales con estos filtros.';
});

const sucursalesConPendientes = computed(() =>
    props.agrupaciones.sucursales.filter(
        (s) => s.metricas.pendientes + s.metricas.no_recibidos > 0,
    ),
);

// ───────────── Marcar (optimista con rollback) ─────────────
function reemplazar(fila: RenglonRecepcion) {
    const i = filas.value.findIndex((x) => x.id === fila.id);

    if (i >= 0) {
        filas.value[i] = fila;
    }
}

async function enviarEstado(
    fila: RenglonRecepcion,
    estado: EstadoRecepcionDocumento,
    extra: Record<string, string> = {},
): Promise<boolean> {
    const anterior = { ...fila };
    guardando.value.add(fila.id);
    reemplazar({ ...fila, estado, estado_etiqueta: ETIQUETAS_ESTADO[estado] });

    try {
        const r = await enviarJson<RespuestaMarcar>(
            'PATCH',
            marcar.url(fila.id),
            { estado, ...extra },
        );
        reemplazar(r.renglon);
        metricas.value = r.metricas;
        estadoCorte.value = r.estado_corte;
        toast.success(
            `${r.renglon.tipo_etiqueta}: ${r.renglon.estado_etiqueta.toLowerCase()}.`,
        );

        return true;
    } catch (e) {
        reemplazar(anterior);
        toast.error(
            e instanceof ErrorSolicitud
                ? e.message
                : 'No se pudo guardar. Revisa tu conexión e intenta de nuevo.',
        );

        return false;
    } finally {
        guardando.value.delete(fila.id);
    }
}

function alternarRecibido(fila: RenglonRecepcion) {
    if (!props.permisos.recibir || guardando.value.has(fila.id)) {
        return;
    }

    void enviarEstado(fila, esRecibido(fila) ? 'pendiente' : 'recibido');
}

// Observación / No recibido / No aplica: único diálogo con texto obligatorio.
const dialogo = reactive({
    abierto: false,
    fila: null as RenglonRecepcion | null,
    estado: 'recibido_con_observacion' as EstadoRecepcionDocumento,
    texto: '',
    enviando: false,
});

const TITULOS_DIALOGO: Partial<Record<EstadoRecepcionDocumento, string>> = {
    recibido_con_observacion: 'Recibido con observación',
    no_recibido: 'Marcar como no recibido',
    no_aplica: 'Marcar como «No aplica»',
};

function abrirDialogo(
    fila: RenglonRecepcion,
    estado: EstadoRecepcionDocumento,
) {
    Object.assign(dialogo, {
        abierto: true,
        fila,
        estado,
        texto:
            estado === 'no_aplica'
                ? (fila.justificacion ?? '')
                : (fila.observaciones ?? ''),
        enviando: false,
    });
}

async function confirmarDialogo() {
    if (!dialogo.fila || dialogo.texto.trim() === '') {
        toast.error(
            dialogo.estado === 'no_aplica'
                ? 'Escribe por qué este original no aplica.'
                : 'Escribe la observación.',
        );

        return;
    }

    dialogo.enviando = true;
    const campo =
        dialogo.estado === 'no_aplica' ? 'justificacion' : 'observaciones';
    const ok = await enviarEstado(dialogo.fila, dialogo.estado, {
        [campo]: dialogo.texto.trim(),
    });
    dialogo.enviando = false;

    if (ok) {
        dialogo.abierto = false;
    }
}

// ───────────── Historial del renglón ─────────────
const historialRenglon = reactive({
    abierto: false,
    cargando: false,
    error: false,
    fila: null as RenglonRecepcion | null,
    eventos: [] as HistorialRecepcion[],
});

async function verHistorial(fila: RenglonRecepcion) {
    Object.assign(historialRenglon, {
        abierto: true,
        cargando: true,
        error: false,
        fila,
        eventos: [],
    });

    try {
        const r = await getJson<{ data: HistorialRecepcion[] }>(
            rutaHistorial.url(fila.id),
        );
        historialRenglon.eventos = r.data;
    } catch {
        historialRenglon.error = true;
    } finally {
        historialRenglon.cargando = false;
    }
}

function describirEvento(e: HistorialRecepcion): string {
    const despues = e.propiedades.despues as
        | {
              estado?: string;
              observaciones?: string | null;
              justificacion?: string | null;
          }
        | undefined;

    if (e.accion === 'recepcion_documental_evidencia') {
        return `Subió evidencia: ${String(e.propiedades.archivo ?? '')}`;
    }

    const estado = despues?.estado as EstadoRecepcionDocumento | undefined;
    const texto = despues?.justificacion ?? despues?.observaciones;

    return [estado ? ETIQUETAS_ESTADO[estado] : e.accion, texto]
        .filter(Boolean)
        .join(' — ');
}

// ───────────── Evidencia ─────────────
const archivoInput = ref<HTMLInputElement | null>(null);
const filaEvidencia = ref<RenglonRecepcion | null>(null);

function elegirEvidencia(fila: RenglonRecepcion) {
    filaEvidencia.value = fila;
    archivoInput.value?.click();
}

async function subirEvidencia(evento: Event) {
    const input = evento.target as HTMLInputElement;
    const archivo = input.files?.[0];
    const fila = filaEvidencia.value;
    input.value = '';

    if (!archivo || !fila) {
        return;
    }

    const datos = new FormData();
    datos.append('archivo', archivo);

    try {
        const respuesta = await fetch(rutaEvidencia.url(fila.id), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            credentials: 'same-origin',
            body: datos,
        });
        const cuerpo = (await respuesta.json().catch(() => null)) as {
            renglon?: RenglonRecepcion;
            message?: string;
            errors?: Record<string, string[]>;
        } | null;

        if (!respuesta.ok || !cuerpo?.renglon) {
            throw new Error(
                (cuerpo?.errors
                    ? Object.values(cuerpo.errors)[0]?.[0]
                    : undefined) ??
                    cuerpo?.message ??
                    'No se pudo subir la evidencia.',
            );
        }

        reemplazar(cuerpo.renglon);
        toast.success('Evidencia guardada.');
    } catch (e) {
        toast.error(
            e instanceof Error ? e.message : 'No se pudo subir la evidencia.',
        );
    }
}

// ───────────── Corte: actualizar, cerrar, reabrir, PDF ─────────────
const procesando = ref(false);

function actualizarCorte() {
    router.post(
        materializar.url(props.corte.id),
        {},
        {
            preserveScroll: true,
            onStart: () => (procesando.value = true),
            onFinish: () => (procesando.value = false),
        },
    );
}

const dialogoCorte = reactive({
    abierto: false,
    accion: 'cerrar' as 'cerrar' | 'reabrir',
    texto: '',
    error: '',
});

function abrirDialogoCorte(accion: 'cerrar' | 'reabrir') {
    Object.assign(dialogoCorte, {
        abierto: true,
        accion,
        texto: '',
        error: '',
    });
}

function confirmarCorte() {
    const url =
        dialogoCorte.accion === 'cerrar'
            ? rutaCerrar.url(props.corte.id)
            : rutaReabrir.url(props.corte.id);
    const campo = dialogoCorte.accion === 'cerrar' ? 'observaciones' : 'motivo';

    router.post(
        url,
        { [campo]: dialogoCorte.texto },
        {
            preserveScroll: true,
            onSuccess: () => (dialogoCorte.abierto = false),
            onError: (errores) =>
                (dialogoCorte.error =
                    Object.values(errores)[0] ??
                    'No se pudo completar la acción.'),
        },
    );
}

function urlPdf(variante: 'control' | 'estado'): string {
    return rutaPdf.url(props.corte.id, {
        query: { variante, ...parametrosFiltro() },
    });
}

// «Recibido y no recibido» es la opción vacía del select.
const opcionesFiltroRecibido = [
    { value: 'si', label: 'Solo recibidos' },
    { value: 'no', label: 'Solo sin recibir' },
];
</script>

<template>
    <Head :title="`Recepción documental · ${corte.etiqueta}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader
            :titulo="`Recepción documental · ${corte.etiqueta}`"
            :descripcion="`Del ${formatearFecha(corte.fecha_inicio)} al ${formatearFecha(corte.fecha_fin)}. Palomea cada original al recibirlo; el digital ya existe, aquí se controla el físico.`"
            :icono="PackageCheck"
            detalle
        >
            <Button
                v-if="permisos.generar"
                variant="outline"
                :disabled="procesando"
                @click="actualizarCorte"
            >
                <RefreshCw
                    class="size-4"
                    :class="{ 'animate-spin': procesando }"
                />
                Actualizar corte
            </Button>
            <Button v-if="permisos.descargar_pdf" variant="outline" as-child>
                <a :href="urlPdf('control')" target="_blank" rel="noopener"
                    ><FileDown class="size-4" /> PDF para marcar</a
                >
            </Button>
            <Button v-if="permisos.descargar_pdf" variant="outline" as-child>
                <a :href="urlPdf('estado')" target="_blank" rel="noopener"
                    ><FileDown class="size-4" /> PDF con estado</a
                >
            </Button>
            <Button v-if="permisos.cerrar" @click="abrirDialogoCorte('cerrar')">
                <Lock class="size-4" /> Cerrar corte
            </Button>
            <Button
                v-if="permisos.reabrir"
                variant="outline"
                @click="abrirDialogoCorte('reabrir')"
            >
                <LockOpen class="size-4" /> Reabrir
            </Button>
        </CrudPageHeader>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <Badge :variant="VARIANTE_CORTE[estadoCorte.value]">{{
                estadoCorte.label
            }}</Badge>
            <span v-if="corte.materializado_en" class="text-muted-foreground"
                >Calculado
                {{ formatearFechaHora(corte.materializado_en) }}</span
            >
            <span
                v-if="cerrado && corte.cerrado_por"
                class="text-muted-foreground"
                >· Cerrado por {{ corte.cerrado_por }}
                {{ formatearFechaHora(corte.cerrado_en) }}</span
            >
            <span
                v-if="corte.reabierto_conteo > 0"
                class="text-muted-foreground"
                >· Reabierto {{ corte.reabierto_conteo }} vez(es): «{{
                    corte.motivo_reapertura
                }}»</span
            >
        </div>

        <CrudStats :estadisticas="estadisticas" />

        <!-- Agrupaciones: localizar rápido dónde falta -->
        <div class="grid gap-3 lg:grid-cols-3">
            <section
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <h2 class="mb-2 text-sm font-medium">
                    Sucursales con pendientes
                </h2>
                <ul v-if="sucursalesConPendientes.length" class="space-y-1">
                    <li v-for="s in sucursalesConPendientes" :key="s.nombre">
                        <button
                            type="button"
                            class="flex min-h-9 w-full fila-interactiva items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-sm"
                            @click="verSucursal(s.sucursal_id)"
                        >
                            <span class="truncate">{{ s.nombre }}</span>
                            <span class="shrink-0 text-xs text-warning"
                                >{{
                                    s.metricas.pendientes +
                                    s.metricas.no_recibidos
                                }}
                                de {{ s.metricas.esperados }}</span
                            >
                        </button>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    {{
                        metricas.renglones
                            ? 'Ninguna sucursal tiene originales pendientes.'
                            : 'Sin originales esperados en este corte.'
                    }}
                </p>
            </section>
            <section
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <h2 class="mb-2 text-sm font-medium">Por tipo documental</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="t in agrupaciones.tipos" :key="t.tipo_documento">
                        <button
                            type="button"
                            class="flex min-h-9 w-full fila-interactiva items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left"
                            @click="f.tipo_documento = t.tipo_documento"
                        >
                            <span class="truncate">{{ t.etiqueta }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground"
                                >{{ t.metricas.recibidos }}/{{
                                    t.metricas.esperados
                                }}</span
                            >
                        </button>
                    </li>
                </ul>
            </section>
            <section
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4"
            >
                <h2 class="mb-2 text-sm font-medium">Por evento de origen</h2>
                <ul class="space-y-1 text-sm">
                    <li v-for="r in agrupaciones.reglas" :key="r.regla">
                        <button
                            v-if="r.metricas.renglones > 0"
                            type="button"
                            class="flex min-h-9 w-full fila-interactiva items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left"
                            @click="f.regla = r.regla"
                        >
                            <span class="truncate">{{ r.etiqueta }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground"
                                >{{ r.metricas.recibidos }}/{{
                                    r.metricas.esperados
                                }}</span
                            >
                        </button>
                        <p
                            v-else
                            class="px-2 py-1.5 text-xs text-muted-foreground"
                        >
                            {{ r.sin_eventos }}
                        </p>
                    </li>
                </ul>
            </section>
        </div>

        <!-- Filtros -->
        <div
            class="flex flex-col gap-2 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-3 lg:flex-row lg:flex-wrap lg:items-center"
        >
            <SelectSimple
                v-model="f.sucursal_id"
                :opciones="opciones.sucursales"
                opcion-vacia="Todas las sucursales"
                placeholder="Todas las sucursales"
                class="w-full lg:w-48"
                aria-label="Sucursal"
            />
            <SelectSimple
                v-model="f.regla"
                :opciones="opciones.reglas"
                opcion-vacia="Todos los eventos"
                placeholder="Todos los eventos"
                class="w-full lg:w-44"
                aria-label="Evento"
            />
            <SelectSimple
                v-model="f.tipo_documento"
                :opciones="opciones.tipos"
                opcion-vacia="Todos los documentos"
                placeholder="Todos los documentos"
                class="w-full lg:w-56"
                aria-label="Tipo documental"
            />
            <SelectSimple
                v-model="f.estado"
                :opciones="opciones.estados"
                opcion-vacia="Todos los estados"
                placeholder="Todos los estados"
                class="w-full lg:w-48"
                aria-label="Estado"
            />
            <SelectSimple
                v-model="f.recibido"
                :opciones="opcionesFiltroRecibido"
                opcion-vacia="Recibido y no recibido"
                placeholder="Recibido y no recibido"
                class="w-full lg:w-48"
                aria-label="Recibido"
            />
            <CrudSearchInput
                v-model="f.colaborador"
                placeholder="Buscar colaborador o número…"
                class="lg:ml-auto"
            />
            <Button
                v-if="hayFiltros"
                variant="ghost"
                size="sm"
                @click="limpiarFiltros"
                >Quitar filtros</Button
            >
        </div>

        <p v-if="hayFiltros" class="-mt-3 text-xs text-muted-foreground">
            Con estos filtros: {{ metricasFiltradas.esperados }} esperados ·
            {{ metricasFiltradas.recibidos }} recibidos ·
            {{ metricasFiltradas.pendientes }} pendientes.
        </p>

        <!-- Lista agrupada: SUCURSAL → evento → persona → documentos -->
        <section class="flex flex-col gap-4">
            <article
                v-for="g in grupos"
                :key="g.sucursal"
                class="overflow-hidden rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
            >
                <header class="bg-salvia px-4 py-2 text-sm font-semibold">
                    {{ g.sucursal }}
                </header>
                <div
                    v-for="b in g.bloques"
                    :key="b.regla"
                    class="border-t border-[var(--mrl-borde)] px-4 py-3"
                >
                    <h3
                        class="mb-2 text-xs font-semibold tracking-wide text-muted-foreground"
                    >
                        {{ b.etiqueta }}
                    </h3>
                    <div
                        v-for="e in b.eventos"
                        :key="e.clave"
                        class="mb-3 last:mb-0"
                    >
                        <p class="text-sm font-medium">{{ e.titulo }}</p>
                        <p
                            v-if="e.descripcion"
                            class="text-xs text-muted-foreground"
                        >
                            {{ e.descripcion }}
                        </p>
                        <ul class="mt-1 divide-y divide-[var(--mrl-borde)]">
                            <li
                                v-for="fila in e.filas"
                                :key="fila.id"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 py-1.5"
                            >
                                <Checkbox
                                    :model-value="esRecibido(fila)"
                                    :disabled="
                                        !permisos.recibir ||
                                        cerrado ||
                                        guardando.has(fila.id) ||
                                        fila.estado === 'no_aplica'
                                    "
                                    :aria-label="`Recibido: ${fila.tipo_etiqueta}`"
                                    class="size-5"
                                    @update:model-value="alternarRecibido(fila)"
                                />
                                <span class="min-w-0 flex-1 text-sm">
                                    {{
                                        fila.regla === 'recibos_nomina'
                                            ? fila.evento_descripcion
                                            : fila.tipo_etiqueta
                                    }}
                                    <span
                                        v-if="fila.cantidad_esperada > 1"
                                        class="text-xs text-muted-foreground"
                                        >× {{ fila.cantidad_esperada }}</span
                                    >
                                    <details
                                        v-if="fila.integrantes?.length"
                                        class="text-xs text-muted-foreground"
                                    >
                                        <summary
                                            class="cursor-pointer rounded-sm transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            {{ fila.evento_descripcion }} — ver
                                            nombres
                                        </summary>
                                        <ul class="mt-1 list-disc pl-5">
                                            <li
                                                v-for="p in fila.integrantes"
                                                :key="p.id"
                                            >
                                                {{ p.nombre
                                                }}{{
                                                    p.numero_empleado
                                                        ? ` (No. ${p.numero_empleado})`
                                                        : ''
                                                }}
                                            </li>
                                        </ul>
                                    </details>
                                    <span
                                        v-if="
                                            fila.observaciones ||
                                            fila.justificacion
                                        "
                                        class="block text-xs text-muted-foreground"
                                        >{{
                                            fila.estado === 'no_aplica'
                                                ? fila.justificacion
                                                : fila.observaciones
                                        }}</span
                                    >
                                </span>
                                <span
                                    v-if="fila.documento_digital"
                                    class="hidden text-xs text-muted-foreground md:inline"
                                    >Digital:
                                    {{
                                        fila.documento_digital.estado ??
                                        'generado'
                                    }}</span
                                >
                                <span
                                    v-if="fila.recibido_en"
                                    class="hidden text-xs text-muted-foreground md:inline"
                                    >{{ fila.recibido_por }} ·
                                    {{
                                        formatearFechaHora(fila.recibido_en)
                                    }}</span
                                >
                                <Badge
                                    :variant="VARIANTE_RENGLON[fila.estado]"
                                    >{{ fila.estado_etiqueta }}</Badge
                                >
                                <DropdownMenu>
                                    <DropdownMenuTrigger as-child>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-10 sm:size-8"
                                            :aria-label="`Más acciones: ${fila.tipo_etiqueta}`"
                                        >
                                            <MoreHorizontal class="size-4" />
                                        </Button>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent align="end">
                                        <template
                                            v-if="permisos.observar && !cerrado"
                                        >
                                            <DropdownMenuItem
                                                @click="
                                                    abrirDialogo(
                                                        fila,
                                                        'recibido_con_observacion',
                                                    )
                                                "
                                                >Recibido con
                                                observación…</DropdownMenuItem
                                            >
                                            <DropdownMenuItem
                                                @click="
                                                    abrirDialogo(
                                                        fila,
                                                        'no_recibido',
                                                    )
                                                "
                                                >No recibido…</DropdownMenuItem
                                            >
                                            <DropdownMenuItem
                                                @click="
                                                    abrirDialogo(
                                                        fila,
                                                        'no_aplica',
                                                    )
                                                "
                                                >No aplica…</DropdownMenuItem
                                            >
                                        </template>
                                        <DropdownMenuItem
                                            v-if="
                                                permisos.recibir &&
                                                !cerrado &&
                                                fila.estado !== 'pendiente'
                                            "
                                            @click="
                                                enviarEstado(fila, 'pendiente')
                                            "
                                            >Regresar a
                                            pendiente</DropdownMenuItem
                                        >
                                        <DropdownMenuItem
                                            v-if="
                                                (permisos.recibir ||
                                                    permisos.observar) &&
                                                !cerrado
                                            "
                                            @click="elegirEvidencia(fila)"
                                        >
                                            <Paperclip class="size-4" /> Subir
                                            evidencia
                                        </DropdownMenuItem>
                                        <DropdownMenuItem
                                            v-if="fila.tiene_evidencia"
                                            as-child
                                        >
                                            <a
                                                :href="
                                                    verEvidencia.url(fila.id)
                                                "
                                                target="_blank"
                                                rel="noopener"
                                                >Ver evidencia</a
                                            >
                                        </DropdownMenuItem>
                                        <DropdownMenuSeparator />
                                        <DropdownMenuItem
                                            @click="verHistorial(fila)"
                                        >
                                            <History class="size-4" /> Historial
                                        </DropdownMenuItem>
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </li>
                        </ul>
                    </div>
                </div>
            </article>

            <div
                v-if="!filas.length"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]"
            >
                <EmptyState
                    v-if="hayFiltros"
                    :icono="PackageCheck"
                    titulo="Sin originales con estos filtros"
                    :descripcion="textoVacioFiltrado"
                >
                    <Button variant="outline" size="sm" @click="limpiarFiltros"
                        >Quitar filtros</Button
                    >
                </EmptyState>
                <EmptyState
                    v-else-if="corte.estado === 'en_preparacion'"
                    :icono="PackageCheck"
                    titulo="Este corte todavía no se calcula"
                    descripcion="Presiona «Actualizar corte» para armar la lista de originales esperados."
                />
                <EmptyState
                    v-else
                    :icono="PackageCheck"
                    titulo="Sin originales esperados"
                    :descripcion="
                        reglasSinEventos.map((r) => r.sin_eventos).join(' ') ||
                        'Este corte no espera originales.'
                    "
                />
            </div>
        </section>

        <PaginacionTabla :datos="renglones" />

        <section
            v-if="pdfs.length || historial.length"
            class="grid gap-3 lg:grid-cols-2"
        >
            <div
                v-if="pdfs.length"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
            >
                <h2 class="mb-2 font-medium">PDF generados</h2>
                <ul class="space-y-1">
                    <li v-for="p in pdfs" :key="p.id">
                        <a
                            :href="verPdf.url({ corte: corte.id, pdf: p.id })"
                            target="_blank"
                            rel="noopener"
                            class="enlace-animado text-primary"
                            >{{
                                p.variante === 'estado'
                                    ? 'Con estado'
                                    : 'Para marcar'
                            }}
                            v{{ p.version }}</a
                        >
                        <span class="text-xs text-muted-foreground">
                            · {{ formatearFechaHora(p.generado_en) }} ·
                            {{ p.generado_por }}</span
                        >
                    </li>
                </ul>
            </div>
            <div
                v-if="historial.length"
                class="rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 text-sm"
            >
                <h2 class="mb-2 font-medium">Historial del corte</h2>
                <ul class="space-y-1">
                    <li v-for="(h, i) in historial" :key="i">
                        <span class="font-medium">{{
                            {
                                recepcion_documental_materializada:
                                    'Cálculo del corte',
                                recepcion_documental_corte_cerrado: 'Cierre',
                                recepcion_documental_corte_reabierto:
                                    'Reapertura',
                                recepcion_documental_pdf_generado:
                                    'PDF generado',
                            }[h.accion] ?? h.accion
                        }}</span>
                        <span class="text-xs text-muted-foreground">
                            · {{ h.por ?? 'Sistema' }} ·
                            {{ formatearFechaHora(h.en) }}</span
                        >
                        <span
                            v-if="
                                h.propiedades.motivo ||
                                h.propiedades.observaciones
                            "
                            class="block text-xs text-muted-foreground"
                            >{{
                                h.propiedades.motivo ??
                                h.propiedades.observaciones
                            }}</span
                        >
                    </li>
                </ul>
            </div>
        </section>
    </div>

    <input
        ref="archivoInput"
        type="file"
        class="hidden"
        accept=".pdf,.jpg,.jpeg,.png,.webp"
        @change="subirEvidencia"
    />

    <!-- Observación / No recibido / No aplica -->
    <Dialog v-model:open="dialogo.abierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ TITULOS_DIALOGO[dialogo.estado] }}</DialogTitle>
                <DialogDescription>
                    {{ dialogo.fila?.tipo_etiqueta }} ·
                    {{ dialogo.fila?.colaborador.nombre ?? 'Sin persona' }}
                </DialogDescription>
            </DialogHeader>
            <Textarea
                v-model="dialogo.texto"
                rows="4"
                :placeholder="
                    dialogo.estado === 'no_aplica'
                        ? 'Por qué este original no aplica (obligatorio)'
                        : 'Qué llegó mal o por qué no llegó (obligatorio)'
                "
            />
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="dialogo.abierto = false"
                    >Cancelar</Button
                >
                <Button :disabled="dialogo.enviando" @click="confirmarDialogo"
                    >Guardar</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Cerrar / Reabrir -->
    <Dialog v-model:open="dialogoCorte.abierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{
                    dialogoCorte.accion === 'cerrar'
                        ? `Cerrar el corte de ${corte.etiqueta}`
                        : `Reabrir el corte de ${corte.etiqueta}`
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        dialogoCorte.accion === 'cerrar'
                            ? 'Antes de cerrar se recalcula por última vez. Cerrado ya no cambia ni se recalcula. Si quedan originales sin recibir, explica por qué.'
                            : 'El motivo es obligatorio y queda en el historial del corte.'
                    }}
                </DialogDescription>
            </DialogHeader>
            <Textarea
                v-model="dialogoCorte.texto"
                rows="4"
                :placeholder="
                    dialogoCorte.accion === 'cerrar'
                        ? 'Observaciones del cierre'
                        : 'Motivo de la reapertura (obligatorio)'
                "
            />
            <p v-if="dialogoCorte.error" class="text-sm text-destructive">
                {{ dialogoCorte.error }}
            </p>
            <DialogFooter class="gap-2">
                <Button variant="outline" @click="dialogoCorte.abierto = false"
                    >Cancelar</Button
                >
                <Button @click="confirmarCorte">{{
                    dialogoCorte.accion === 'cerrar'
                        ? 'Cerrar corte'
                        : 'Reabrir'
                }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Historial del renglón -->
    <Dialog v-model:open="historialRenglon.abierto">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Historial</DialogTitle>
                <DialogDescription>
                    {{ historialRenglon.fila?.tipo_etiqueta }} ·
                    {{
                        historialRenglon.fila?.colaborador.nombre ??
                        'Sin persona'
                    }}
                </DialogDescription>
            </DialogHeader>
            <div
                v-if="historialRenglon.cargando"
                class="space-y-3"
                role="status"
                aria-label="Cargando historial"
            >
                <Skeleton v-for="n in 3" :key="n" class="h-9 w-full" />
            </div>
            <p
                v-else-if="historialRenglon.error"
                class="text-sm text-destructive"
            >
                No se pudo cargar el historial. Intenta de nuevo.
            </p>
            <p
                v-else-if="!historialRenglon.eventos.length"
                class="text-sm text-muted-foreground"
            >
                Este original todavía no tiene movimientos.
            </p>
            <ul
                v-else
                class="max-h-[60vh] entrada-escalonada space-y-2 overflow-y-auto text-sm"
            >
                <li v-for="(h, i) in historialRenglon.eventos" :key="i">
                    <span class="font-medium">{{ describirEvento(h) }}</span>
                    <span class="block text-xs text-muted-foreground"
                        >{{ h.por ?? 'Sistema' }} ·
                        {{ formatearFechaHora(h.en) }}</span
                    >
                </li>
            </ul>
        </DialogContent>
    </Dialog>
</template>
