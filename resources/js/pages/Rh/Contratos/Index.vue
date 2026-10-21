<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Ban,
    CalendarDays,
    CheckCircle2,
    ChevronDown,
    CircleSlash,
    ClipboardList,
    FileCheck2,
    FilePlus2,
    FileSignature,
    FileText,
    Hourglass,
    PencilLine,
    RefreshCw,
    ScrollText,
    Settings2,
    Sparkles,
    Timer,
    Users,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AyudaBoton from '@/components/Common/AyudaBoton.vue';
import ColaboradorAvatar from '@/components/Common/ColaboradorAvatar.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import CrudToolbar from '@/components/DataTable/CrudToolbar.vue';
import PaginacionTabla from '@/components/DataTable/PaginacionTabla.vue';
import CamposDatosFaltantes from '@/components/documentos/CamposDatosFaltantes.vue';
import BotonVistaPrevia from '@/components/people/BotonVistaPrevia.vue';
import ChipEstado from '@/components/people/ChipEstado.vue';
import ChipsCompactos from '@/components/people/ChipsCompactos.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import TarjetaMetrica from '@/components/people/TarjetaMetrica.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Progress } from '@/components/ui/progress';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useFiltros } from '@/composables/useFiltros';
import { useVistaPreviaPdf } from '@/composables/useVistaPreviaPdf';
import { datosFaltantesCompletos } from '@/lib/datosFaltantes';
import { getJson } from '@/lib/http';
import type { Tono } from '@/lib/tonos';
import {
    completarDatos as completarDatosRuta,
    generar as generarRuta,
    generarTodos as generarTodosRuta,
    index as indexContratos,
    lote as loteRuta,
    vistaPrevia as vistaPreviaRuta,
} from '@/routes/rh/contratos';
import { index as indexContratosIngreso } from '@/routes/rh/contratos-ingreso';
import type {
    DocumentoCorrespondiente,
    EstadoContratoCorrespondiente,
    LoteContratos,
    PlanContratos,
    RespuestaPaginada,
} from '@/types';

type Opcion = { id: number; nombre: string };

const props = defineProps<{
    filas: RespuestaPaginada<PlanContratos>;
    resumen: Record<string, number>;
    faltantesComunes: { etiqueta: string; total: number }[];
    opciones: {
        sucursales: Opcion[];
        puestos: Opcion[];
        estados: { value: string; label: string }[];
    };
    filtros: {
        busqueda?: string;
        sucursal_id?: string;
        puesto_id?: string;
        estado?: string;
    };
    lote: LoteContratos | null;
    puedeGenerar: boolean;
    puedeCompletar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: '/dashboard' },
            { title: 'Contratos', href: '/rh/contratos' },
        ],
    },
});

const { filtros, aplicar, aplicarConDebounce, limpiar } = useFiltros(
    indexContratos.url(),
    {
        busqueda: props.filtros.busqueda ?? '',
        sucursal_id: props.filtros.sucursal_id ?? '',
        puesto_id: props.filtros.puesto_id ?? '',
        estado: props.filtros.estado ?? '',
    },
);

const filtrosActivos = computed(
    () =>
        [filtros.sucursal_id, filtros.puesto_id, filtros.estado].filter(
            (v) => v !== '',
        ).length,
);

function formatoFecha(fecha: string | null): string {
    if (!fecha) {
        return '—';
    }

    const [a, m, d] = fecha.split('-');

    return `${d}/${m}/${a}`;
}

// ───────── Estados: un color + un ícono por estado, en toda la pantalla ─────────
type InfoEstado = { tono: Tono; icono: Component; corta: string };

const ESTADOS: Record<EstadoContratoCorrespondiente, InfoEstado> = {
    generado: { tono: 'exito', icono: CheckCircle2, corta: 'En expediente' },
    completo: { tono: 'exito', icono: CheckCircle2, corta: 'Completo' },
    por_generar: { tono: 'info', icono: FilePlus2, corta: 'Por generar' },
    faltan_datos: {
        tono: 'peligro',
        icono: AlertTriangle,
        corta: 'Faltan datos',
    },
    formato_no_listo: {
        tono: 'alerta',
        icono: Settings2,
        corta: 'Formato no listo',
    },
    pendiente_evaluacion: {
        tono: 'alerta',
        icono: Hourglass,
        corta: 'Pendiente de evaluación',
    },
    no_aplica: { tono: 'neutro', icono: CircleSlash, corta: 'No aplica' },
};

function estado(e: EstadoContratoCorrespondiente): InfoEstado {
    return ESTADOS[e] ?? ESTADOS.no_aplica;
}

/** Tarjetas del resumen: también son el filtro por estado. */
const tarjetas = computed(() => {
    const r = props.resumen;
    const lista: {
        valor: string;
        etiqueta: string;
        total: number;
        tono: Tono;
        icono: Component;
    }[] = [
        {
            valor: '',
            etiqueta: 'Todos',
            total: r.total ?? 0,
            tono: 'primario',
            icono: Users,
        },
        {
            valor: 'completo',
            etiqueta: 'Completos',
            total: r.completo ?? 0,
            tono: 'exito',
            icono: CheckCircle2,
        },
        {
            valor: 'por_generar',
            etiqueta: 'Por generar',
            total: r.por_generar ?? 0,
            tono: 'info',
            icono: FilePlus2,
        },
        {
            valor: 'faltan_datos',
            etiqueta: 'Faltan datos',
            total: r.faltan_datos ?? 0,
            tono: 'peligro',
            icono: AlertTriangle,
        },
        {
            valor: 'formato_no_listo',
            etiqueta: 'Formato no listo',
            total: r.formato_no_listo ?? 0,
            tono: 'alerta',
            icono: Settings2,
        },
        {
            valor: 'pendiente_evaluacion',
            etiqueta: 'Pend. de evaluación',
            total: r.pendiente_evaluacion ?? 0,
            tono: 'alerta',
            icono: Hourglass,
        },
    ];

    if ((r.no_aplica ?? 0) > 0) {
        lista.push({
            valor: 'no_aplica',
            etiqueta: 'No aplica',
            total: r.no_aplica ?? 0,
            tono: 'neutro',
            icono: CircleSlash,
        });
    }

    return lista;
});

function filtrarPorEstado(valor: string) {
    filtros.estado = filtros.estado === valor ? '' : valor;
    aplicar();
}

// ───────── Vista previa (motor documental, nada se guarda) ─────────
const visor = useVistaPreviaPdf();

function claveVisor(fila: PlanContratos, doc: DocumentoCorrespondiente) {
    return `${fila.id}:${doc.clave}`;
}

function verDocumento(fila: PlanContratos, doc: DocumentoCorrespondiente) {
    void visor.abrir([
        {
            clave: claveVisor(fila, doc),
            nombre: `${doc.nombre} · ${fila.nombre}`,
            url: vistaPreviaRuta.url({
                colaborador: fila.id,
                clave: doc.clave,
            }),
        },
    ]);
}

function puedeVerPrevia(doc: DocumentoCorrespondiente): boolean {
    return doc.estado === 'por_generar' || doc.estado === 'faltan_datos';
}

// ───────── Archivo ya existente (firmado cargado o generado) en el visor ─────────
const archivo = ref<{
    url: string;
    nombre: string;
    mime: string | null;
    estado: string;
} | null>(null);

function verFirmado(fila: PlanContratos, doc: DocumentoCorrespondiente) {
    if (!doc.firmado_cargado) {
        return;
    }

    archivo.value = {
        url: doc.firmado_cargado.url,
        nombre: doc.firmado_cargado.nombre || `${doc.nombre} firmado`,
        mime: doc.firmado_cargado.mime,
        estado: `Contrato firmado · ${fila.nombre}`,
    };
}

function verGenerado(fila: PlanContratos, doc: DocumentoCorrespondiente) {
    if (!doc.descargar_url) {
        return;
    }

    archivo.value = {
        url: doc.descargar_url,
        nombre: `${doc.nombre}.pdf`,
        mime: 'application/pdf',
        estado: `En expediente · ${fila.nombre}`,
    };
}

// ───────── Generar por colaborador ─────────
const generando = ref<number | null>(null);

function generar(fila: PlanContratos) {
    generando.value = fila.id;
    router.post(
        generarRuta.url(fila.id),
        {},
        {
            preserveScroll: true,
            onFinish: () => (generando.value = null),
        },
    );
}

// ───────── Completar datos faltantes ─────────
const completando = ref<PlanContratos | null>(null);
const valores = ref<Record<string, string>>({});
const guardando = ref(false);
const editablesCompletos = computed(() =>
    completando.value
        ? datosFaltantesCompletos(completando.value.faltantes, valores.value)
        : false,
);
const hayEditables = computed(
    () => completando.value?.faltantes.some((f) => f.editable) ?? false,
);

function abrirCompletar(fila: PlanContratos) {
    completando.value = fila;
    valores.value = {};
}

function guardarDatos() {
    if (!completando.value) {
        return;
    }

    guardando.value = true;
    router.post(
        completarDatosRuta.url(completando.value.id),
        { datos: valores.value },
        {
            preserveScroll: true,
            onSuccess: () => (completando.value = null),
            onFinish: () => (guardando.value = false),
        },
    );
}

// ───────── Generar todos (lote en la cola) ─────────
const confirmarTodos = ref(false);
const lote = ref<LoteContratos | null>(props.lote);
const verDetalleLote = ref(false);
let sondeo: ReturnType<typeof setInterval> | undefined;

watch(
    () => props.lote,
    (valor) => {
        lote.value = valor;
        vigilarLote();
    },
);

const generablesVisibles = computed(() =>
    props.filas.data.filter((f) => f.generable),
);
const ejemploPrevia = computed(() => {
    for (const fila of generablesVisibles.value) {
        const doc = fila.contratos
            .flatMap((e) => e.documentos)
            .find((d) => d.estado === 'por_generar');

        if (doc) {
            return { fila, doc };
        }
    }

    return null;
});

const porcentaje = computed(() =>
    lote.value && lote.value.total > 0
        ? Math.round((lote.value.procesados / lote.value.total) * 100)
        : 0,
);

function vigilarLote() {
    clearInterval(sondeo);

    if (!lote.value?.en_curso) {
        return;
    }

    const id = lote.value.id;

    sondeo = setInterval(async () => {
        try {
            const respuesta = await getJson<{ data: LoteContratos }>(
                loteRuta.url(id),
            );
            lote.value = respuesta.data;

            if (!respuesta.data.en_curso) {
                clearInterval(sondeo);
                router.reload({
                    only: ['filas', 'resumen', 'faltantesComunes'],
                });
            }
        } catch {
            clearInterval(sondeo);
        }
    }, 3000);
}

vigilarLote();
onBeforeUnmount(() => clearInterval(sondeo));

const enviandoTodos = ref(false);

function generarTodos() {
    enviandoTodos.value = true;
    router.post(
        generarTodosRuta.url(),
        {},
        {
            preserveScroll: true,
            onSuccess: () => (confirmarTodos.value = false),
            onFinish: () => (enviandoTodos.value = false),
        },
    );
}

const resultadosVisibles = computed(() =>
    (lote.value?.resultados ?? []).slice(0, 200),
);

const contadoresLote = computed(() => {
    const l = lote.value;

    if (!l) {
        return [];
    }

    return [
        {
            etiqueta: 'Generados',
            valor: l.generados,
            tono: 'exito' as Tono,
            icono: CheckCircle2,
        },
        {
            etiqueta: 'Ya los tenían',
            valor: l.sin_cambios,
            tono: 'neutro' as Tono,
            icono: FileCheck2,
        },
        {
            etiqueta: 'Omitidos',
            valor: l.omitidos,
            tono: 'alerta' as Tono,
            icono: CircleSlash,
        },
        {
            etiqueta: 'Con error',
            valor: l.errores,
            tono: 'peligro' as Tono,
            icono: Ban,
        },
    ];
});

const TONO_RESULTADO: Record<string, Tono> = {
    generado: 'exito',
    sin_cambios: 'neutro',
    omitido: 'alerta',
    error: 'peligro',
};

const faltantesTexto = computed(() =>
    props.faltantesComunes.map((f) => `${f.etiqueta} · ${f.total}`),
);

function etiquetasFaltantes(fila: PlanContratos): string[] {
    return [...new Set(fila.faltantes.map((f) => f.etiqueta))];
}
</script>

<template>
    <Head title="Contratos" />

    <div class="pagina-ancha flex flex-col gap-5">
        <CrudPageHeader titulo="Contratos" :icono="FileSignature">
            <div class="flex w-full flex-wrap gap-2 sm:w-auto">
                <Button variant="outline" class="flex-1 sm:flex-none" as-child>
                    <Link :href="indexContratosIngreso()">
                        <ScrollText class="size-4" />
                        Contratos de ingreso
                    </Link>
                </Button>
                <Button
                    v-if="puedeGenerar"
                    class="flex-1 sm:flex-none"
                    :disabled="lote?.en_curso === true"
                    @click="confirmarTodos = true"
                >
                    <Spinner v-if="lote?.en_curso" />
                    <Sparkles v-else class="size-4" />
                    {{ lote?.en_curso ? 'Generando…' : 'Generar todos' }}
                </Button>
            </div>
        </CrudPageHeader>

        <!-- Resumen: conteos grandes que también filtran -->
        <section aria-label="Resumen de contratos" class="flex flex-col gap-2">
            <div class="flex items-center gap-1.5">
                <h2 class="text-sm font-semibold text-muted-foreground">
                    Colaboradores activos por estado
                </h2>
                <AyudaBoton
                    titulo="Contratos"
                    texto="A cada colaborador activo le corresponde el contrato inicial (capacitación) con su paquete y, al terminar su periodo de prueba, el contrato por tiempo indeterminado. Toca una tarjeta para filtrar."
                />
            </div>
            <div
                class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 sm:gap-3 xl:auto-cols-fr xl:grid-flow-col xl:grid-cols-none"
            >
                <TarjetaMetrica
                    v-for="t in tarjetas"
                    :key="t.valor"
                    :etiqueta="t.etiqueta"
                    :valor="t.total"
                    :icono="t.icono"
                    :tono="t.tono"
                    clicable
                    :activa="filtros.estado === t.valor"
                    @click="filtrarPorEstado(t.valor)"
                />
            </div>
        </section>

        <!-- Avance de «Generar todos» -->
        <section
            v-if="lote"
            class="flex flex-col gap-4 rounded-2xl border border-border/60 bg-card p-4 shadow-sm sm:p-5"
            aria-live="polite"
        >
            <div class="flex flex-wrap items-center gap-3">
                <span
                    class="flex size-11 shrink-0 items-center justify-center rounded-2xl"
                    :class="
                        lote.en_curso
                            ? 'bg-info-soft text-info'
                            : lote.error
                              ? 'bg-danger-soft text-destructive'
                              : 'bg-success-soft text-success'
                    "
                >
                    <RefreshCw
                        v-if="lote.en_curso"
                        class="size-5 animate-spin motion-reduce:animate-none"
                    />
                    <Ban v-else-if="lote.error" class="size-5" />
                    <CheckCircle2 v-else class="size-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-base font-semibold">
                        Generación en lote · {{ lote.estado_etiqueta }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{ lote.procesados }} de {{ lote.total }} revisados
                        <span v-if="lote.iniciado_por">
                            · {{ lote.iniciado_por }}</span
                        >
                    </p>
                </div>
                <span
                    class="text-3xl font-bold text-primary tabular-nums sm:text-4xl"
                    >{{ porcentaje }}%</span
                >
            </div>

            <Progress :model-value="porcentaje" class="h-3" />

            <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <div
                    v-for="c in contadoresLote"
                    :key="c.etiqueta"
                    class="flex items-center gap-2 rounded-xl bg-[var(--mrl-fondo)] px-3 py-2"
                >
                    <component
                        :is="c.icono"
                        class="size-4 shrink-0"
                        :class="{
                            'text-success': c.tono === 'exito',
                            'text-muted-foreground': c.tono === 'neutro',
                            'text-warning': c.tono === 'alerta',
                            'text-destructive': c.tono === 'peligro',
                        }"
                    />
                    <span class="text-xl font-bold tabular-nums">{{
                        c.valor
                    }}</span>
                    <span class="truncate text-xs text-muted-foreground">{{
                        c.etiqueta
                    }}</span>
                </div>
            </div>

            <p
                v-if="lote.error"
                class="rounded-xl bg-danger-soft px-3 py-2 text-sm text-destructive"
            >
                {{ lote.error }}
            </p>

            <div v-if="resultadosVisibles.length">
                <Button
                    variant="ghost"
                    size="sm"
                    class="-ml-2"
                    :aria-expanded="verDetalleLote"
                    @click="verDetalleLote = !verDetalleLote"
                >
                    <ChevronDown
                        class="size-4 transition-transform"
                        :class="verDetalleLote && 'rotate-180'"
                    />
                    {{ verDetalleLote ? 'Ocultar detalle' : 'Ver detalle' }}
                </Button>
                <ul
                    v-if="verDetalleLote"
                    class="mt-2 flex max-h-80 flex-col divide-y divide-border/50 overflow-y-auto rounded-xl border border-border/60"
                >
                    <li
                        v-for="r in resultadosVisibles"
                        :key="r.colaborador_id"
                        class="flex flex-col gap-1 px-3 py-2 sm:flex-row sm:items-center sm:gap-3"
                    >
                        <ChipEstado
                            :etiqueta="r.resultado_etiqueta"
                            :tono="TONO_RESULTADO[r.resultado] ?? 'neutro'"
                        />
                        <span class="text-sm font-medium">{{ r.nombre }}</span>
                        <span
                            v-if="r.motivo || r.documentos.length"
                            class="min-w-0 truncate text-xs text-muted-foreground sm:flex-1"
                            :title="r.motivo ?? r.documentos.join(', ')"
                            >{{ r.motivo ?? r.documentos.join(', ') }}</span
                        >
                    </li>
                </ul>
            </div>
        </section>

        <!-- Lo que más falta -->
        <section
            v-if="faltantesComunes.length"
            class="flex flex-col gap-2 rounded-2xl border border-border/60 bg-crema/60 p-3 sm:flex-row sm:items-center sm:gap-4 sm:p-4"
        >
            <p
                class="flex shrink-0 items-center gap-2 text-sm font-semibold text-bronce"
            >
                <AlertTriangle class="size-4" />
                Lo que más falta
            </p>
            <ChipsCompactos
                :elementos="faltantesTexto"
                :maximo="4"
                tono="alerta"
            />
        </section>

        <CrudToolbar
            :model-value="filtros.busqueda"
            placeholder="Buscar colaborador o número de empleado…"
            :contador-filtros-activos="filtrosActivos"
            @update:model-value="
                (valor) => {
                    filtros.busqueda = valor;
                    aplicarConDebounce();
                }
            "
            @aplicar-filtros="aplicar"
            @limpiar="limpiar"
        >
            <template #filtros>
                <div
                    class="flex flex-col gap-3 [&_[data-slot=select-trigger]]:w-full"
                >
                    <Select
                        :model-value="filtros.estado"
                        @update:model-value="
                            (v) => {
                                filtros.estado = String(v ?? '');
                                aplicar();
                            }
                        "
                    >
                        <SelectTrigger>
                            <SelectValue placeholder="Estado" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="e in opciones.estados"
                                :key="e.value"
                                :value="e.value"
                                >{{ e.label }}</SelectItem
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
                        <SelectTrigger>
                            <SelectValue placeholder="Sucursal" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="s in opciones.sucursales"
                                :key="s.id"
                                :value="String(s.id)"
                                >{{ s.nombre }}</SelectItem
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
                        <SelectTrigger>
                            <SelectValue placeholder="Puesto" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="p in opciones.puestos"
                                :key="p.id"
                                :value="String(p.id)"
                                >{{ p.nombre }}</SelectItem
                            >
                        </SelectContent>
                    </Select>
                </div>
            </template>
        </CrudToolbar>

        <EmptyState
            v-if="!filas.data.length"
            :icono="FileSignature"
            titulo="Sin colaboradores"
            descripcion="No hay colaboradores activos con esos filtros dentro de tu alcance."
        />

        <!-- Una tarjeta por colaborador (móvil y escritorio) -->
        <ul
            v-else
            class="grid grid-cols-1 gap-4 2xl:grid-cols-2"
            aria-label="Contratos por colaborador"
        >
            <li
                v-for="fila in filas.data"
                :key="fila.id"
                class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-border/60 bg-card shadow-sm"
            >
                <!-- Encabezado: quién es y cómo está -->
                <header
                    class="flex flex-col gap-3 border-b border-border/50 p-4 sm:flex-row sm:items-center"
                >
                    <div class="flex min-w-0 flex-1 items-center gap-3">
                        <ColaboradorAvatar :nombre="fila.nombre" tamano="md" />
                        <div class="min-w-0">
                            <Link
                                :href="fila.expediente_url"
                                class="block truncate text-base font-semibold hover:underline"
                                >{{ fila.nombre }}</Link
                            >
                            <p class="truncate text-sm text-muted-foreground">
                                <span v-if="fila.numero_empleado"
                                    >#{{ fila.numero_empleado }} · </span
                                >{{ fila.puesto ?? 'Sin puesto' }} ·
                                {{ fila.sucursal ?? 'Sin sucursal' }}
                            </p>
                        </div>
                    </div>
                    <ChipEstado
                        :etiqueta="estado(fila.estado).corta"
                        :tono="estado(fila.estado).tono"
                        :icono="estado(fila.estado).icono"
                        tamano="md"
                    />
                </header>

                <div class="flex flex-1 flex-col gap-3 p-4">
                    <!-- Fechas clave -->
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span
                            class="inline-flex items-center gap-1.5 rounded-lg bg-[var(--mrl-fondo)] px-2.5 py-1"
                        >
                            <CalendarDays
                                class="size-3.5 text-muted-foreground"
                            />
                            Ingreso
                            <strong class="tabular-nums">{{
                                formatoFecha(fila.fecha_ingreso)
                            }}</strong>
                        </span>
                        <span
                            v-if="fila.fin_periodo_prueba"
                            class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1"
                            :class="
                                fila.periodo_vencido
                                    ? 'bg-[var(--mrl-fondo)]'
                                    : 'bg-warning-soft text-warning'
                            "
                        >
                            <Timer class="size-3.5" />
                            Prueba
                            <template v-if="fila.meses_periodo_prueba"
                                >({{ fila.meses_periodo_prueba }}
                                {{
                                    fila.meses_periodo_prueba === 1
                                        ? 'mes'
                                        : 'meses'
                                }})</template
                            >
                            {{ fila.periodo_vencido ? 'terminó' : 'termina' }}
                            <strong class="tabular-nums">{{
                                formatoFecha(fila.fin_periodo_prueba)
                            }}</strong>
                        </span>
                    </div>

                    <!-- Bloqueos (puesto sin duración, etc.) -->
                    <div
                        v-for="b in fila.bloqueos"
                        :key="b.etiqueta"
                        class="flex flex-col gap-2 rounded-xl bg-warning-soft p-3 text-sm sm:flex-row sm:items-center"
                    >
                        <p class="flex flex-1 items-start gap-2 text-warning">
                            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                            <span class="text-foreground">{{ b.detalle }}</span>
                        </p>
                        <Button
                            v-if="b.configurar_url"
                            size="sm"
                            variant="outline"
                            as-child
                        >
                            <Link :href="b.configurar_url">
                                <Settings2 class="size-4" />
                                Configurar puesto
                            </Link>
                        </Button>
                    </div>

                    <p
                        v-if="
                            !fila.bloqueos.length &&
                            fila.estado === 'no_aplica' &&
                            fila.motivo
                        "
                        class="flex items-start gap-2 rounded-xl bg-muted/60 p-3 text-sm text-muted-foreground"
                    >
                        <CircleSlash class="mt-0.5 size-4 shrink-0" />
                        {{ fila.motivo }}
                    </p>

                    <!-- Etapas: contrato inicial / indeterminado -->
                    <section
                        v-for="etapa in fila.contratos"
                        :key="etapa.etapa"
                        class="flex flex-col gap-2"
                    >
                        <div class="flex flex-wrap items-baseline gap-x-2">
                            <h3 class="text-sm font-semibold">
                                {{ etapa.etiqueta }}
                            </h3>
                            <span
                                class="text-xs text-muted-foreground tabular-nums"
                            >
                                {{ formatoFecha(etapa.fecha_inicio)
                                }}<template v-if="etapa.fecha_fin">
                                    – {{ formatoFecha(etapa.fecha_fin) }}
                                </template>
                            </span>
                            <span
                                v-if="!etapa.contrato_id"
                                class="text-xs text-muted-foreground"
                                >· sin registrar</span
                            >
                        </div>

                        <ul class="flex flex-col gap-2">
                            <li
                                v-for="doc in etapa.documentos"
                                :key="doc.clave"
                                class="flex flex-col gap-2.5 rounded-xl border border-border/60 bg-[var(--mrl-fondo)] p-3 lg:flex-row lg:items-center"
                            >
                                <div
                                    class="flex min-w-0 flex-1 items-start gap-2.5"
                                >
                                    <span
                                        class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-xl bg-card text-bronce"
                                        aria-hidden="true"
                                    >
                                        <FileText class="size-4" />
                                    </span>
                                    <div class="flex min-w-0 flex-col gap-1">
                                        <p
                                            class="text-sm leading-snug font-medium"
                                        >
                                            {{ doc.nombre }}
                                        </p>
                                        <div
                                            class="flex flex-wrap items-center gap-1.5"
                                        >
                                            <ChipEstado
                                                :etiqueta="
                                                    estado(doc.estado).corta
                                                "
                                                :tono="estado(doc.estado).tono"
                                                :icono="
                                                    estado(doc.estado).icono
                                                "
                                                :titulo="doc.estado_etiqueta"
                                            />
                                        </div>
                                        <p
                                            v-if="
                                                doc.motivo &&
                                                doc.estado !== 'faltan_datos'
                                            "
                                            class="line-clamp-2 text-xs text-muted-foreground"
                                            :title="doc.motivo"
                                        >
                                            {{ doc.motivo }}
                                        </p>
                                    </div>
                                </div>

                                <div
                                    class="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2 lg:flex lg:shrink-0 lg:flex-wrap lg:justify-end min-[420px]:[&>*:only-child]:col-span-2"
                                >
                                    <Button
                                        v-if="doc.firmado_cargado"
                                        variant="success"
                                        class="h-10"
                                        @click="verFirmado(fila, doc)"
                                    >
                                        <FileCheck2 class="size-5" />
                                        Ver contrato firmado
                                    </Button>
                                    <Button
                                        v-if="doc.descargar_url"
                                        variant="outline"
                                        class="h-10"
                                        @click="verGenerado(fila, doc)"
                                    >
                                        <FileText class="size-5" />
                                        Ver generado
                                    </Button>
                                    <BotonVistaPrevia
                                        v-if="puedeVerPrevia(doc)"
                                        class="h-10"
                                        :cargando="
                                            visor.generandoClave(
                                                claveVisor(fila, doc),
                                            )
                                        "
                                        :deshabilitado="visor.cargando.value"
                                        @click="verDocumento(fila, doc)"
                                    />
                                </div>
                            </li>
                        </ul>
                    </section>

                    <!-- Datos faltantes en chips -->
                    <div
                        v-if="fila.faltantes.length"
                        class="flex flex-col gap-1.5"
                    >
                        <p class="text-xs font-semibold text-destructive">
                            Faltan {{ etiquetasFaltantes(fila).length }}
                            {{
                                etiquetasFaltantes(fila).length === 1
                                    ? 'dato'
                                    : 'datos'
                            }}
                        </p>
                        <ChipsCompactos
                            :elementos="etiquetasFaltantes(fila)"
                            :maximo="3"
                        />
                    </div>
                </div>

                <!-- Acciones principales -->
                <footer
                    v-if="
                        (puedeGenerar && fila.generable) ||
                        fila.faltantes.length ||
                        fila.ciclo_url
                    "
                    class="flex flex-col gap-2 border-t border-border/50 bg-[var(--mrl-fondo)]/60 p-3 sm:flex-row sm:items-center sm:justify-end"
                >
                    <Button variant="ghost" class="h-10 sm:mr-auto" as-child>
                        <Link :href="fila.ciclo_url">
                            <ClipboardList class="size-4" />
                            Ciclo laboral
                        </Link>
                    </Button>
                    <Button
                        v-if="fila.faltantes.length"
                        variant="outline"
                        class="h-11 sm:h-10"
                        @click="abrirCompletar(fila)"
                    >
                        <PencilLine class="size-5" />
                        Completar datos
                    </Button>
                    <Button
                        v-if="puedeGenerar && fila.generable"
                        class="h-11 sm:h-10"
                        :disabled="
                            generando !== null || lote?.en_curso === true
                        "
                        @click="generar(fila)"
                    >
                        <Spinner v-if="generando === fila.id" />
                        <FilePlus2 v-else class="size-5" />
                        {{ generando === fila.id ? 'Generando…' : 'Generar' }}
                    </Button>
                </footer>
            </li>
        </ul>

        <PaginacionTabla :datos="filas" />
    </div>

    <!-- Vista previa (se genera; abre de inmediato con el esqueleto) -->
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

    <!-- Contrato firmado o generado que ya está en el expediente -->
    <DocumentPreviewDialog
        :open="archivo !== null"
        :preview-url="archivo?.url ?? null"
        :nombre="archivo?.nombre ?? ''"
        :mime="archivo?.mime ?? null"
        :estado="archivo?.estado ?? null"
        @update:open="(v: boolean) => !v && (archivo = null)"
    />

    <!-- Confirmar «Generar todos» -->
    <Dialog
        :open="confirmarTodos"
        @update:open="(v: boolean) => (confirmarTodos = v)"
    >
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Generar todos los pendientes</DialogTitle>
                <DialogDescription>
                    Solo se genera lo que falta; nunca se duplica. Quien tenga
                    datos faltantes se omite con su motivo.
                </DialogDescription>
            </DialogHeader>
            <ul class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                <li
                    class="flex items-center gap-2 rounded-xl bg-info-soft px-3 py-2"
                >
                    <FilePlus2 class="size-4 text-info" />
                    <strong class="tabular-nums">{{
                        resumen.por_generar ?? 0
                    }}</strong>
                    por generar
                </li>
                <li
                    class="flex items-center gap-2 rounded-xl bg-danger-soft px-3 py-2"
                >
                    <AlertTriangle class="size-4 text-destructive" />
                    <strong class="tabular-nums">{{
                        resumen.faltan_datos ?? 0
                    }}</strong>
                    se omitirán
                </li>
            </ul>
            <div
                v-if="ejemploPrevia"
                class="flex flex-col gap-2 rounded-xl border border-border/60 bg-crema/60 p-3 text-sm sm:flex-row sm:items-center"
            >
                <p class="min-w-0 flex-1">
                    Revisa uno antes:
                    <strong>{{ ejemploPrevia.doc.nombre }}</strong>
                </p>
                <BotonVistaPrevia
                    :cargando="
                        visor.generandoClave(
                            claveVisor(ejemploPrevia.fila, ejemploPrevia.doc),
                        )
                    "
                    :deshabilitado="visor.cargando.value"
                    @click="verDocumento(ejemploPrevia.fila, ejemploPrevia.doc)"
                />
            </div>
            <DialogFooter class="gap-2">
                <Button variant="ghost" @click="confirmarTodos = false"
                    >Cancelar</Button
                >
                <Button
                    size="lg"
                    :disabled="enviandoTodos"
                    @click="generarTodos"
                >
                    <Spinner v-if="enviandoTodos" />
                    <Sparkles v-else class="size-4" />
                    Generar todos
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Completar datos faltantes -->
    <Dialog
        :open="completando !== null"
        @update:open="(v: boolean) => !v && !guardando && (completando = null)"
    >
        <DialogContent
            class="max-h-[100svh] overflow-y-auto max-sm:h-[100svh] max-sm:max-w-none max-sm:rounded-none sm:max-h-[90vh] sm:max-w-lg"
        >
            <DialogHeader>
                <DialogTitle
                    >Datos faltantes de {{ completando?.nombre }}</DialogTitle
                >
                <DialogDescription>
                    Se guardan en su expediente y sirven para todos sus
                    documentos.
                </DialogDescription>
            </DialogHeader>
            <CamposDatosFaltantes
                v-if="completando"
                v-model="valores"
                :faltantes="completando.faltantes"
            />
            <p
                v-if="hayEditables && !puedeCompletar"
                class="text-sm text-destructive"
            >
                No tienes permiso para editar el expediente; pídeselo a RH.
            </p>
            <DialogFooter class="gap-2">
                <Button
                    variant="ghost"
                    :disabled="guardando"
                    @click="completando = null"
                    >Cancelar</Button
                >
                <Button
                    v-if="hayEditables && puedeCompletar"
                    size="lg"
                    :disabled="!editablesCompletos || guardando"
                    @click="guardarDatos"
                >
                    <Spinner v-if="guardando" />
                    {{ guardando ? 'Guardando…' : 'Guardar datos' }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
