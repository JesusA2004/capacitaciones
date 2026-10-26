<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    ChevronLeft,
    ChevronRight,
    Eye,
    History,
    ListChecks,
    Loader2,
    Redo2,
    Save,
    Search,
    Undo2,
    UserPen,
} from '@lucide/vue';
import { onKeyStroke, useEventListener, useMediaQuery } from '@vueuse/core';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import MiniaturaHoja from '@/components/Rh/DivisionHistorico/MiniaturaHoja.vue';
import PanelAccionesHojas from '@/components/Rh/DivisionHistorico/PanelAccionesHojas.vue';
import VistaPreviaHojasDialog from '@/components/Rh/DivisionHistorico/VistaPreviaHojasDialog.vue';
import ZoomHojaDialog from '@/components/Rh/DivisionHistorico/ZoomHojaDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useAlertas } from '@/composables/useAlertas';
import { usePdfDocumento } from '@/composables/usePdfDocumento';
import {
    ESTILO_SIN_CLASIFICAR,
    asignarTipo,
    continuarAnterior,
    crearEstado,
    describirOrden,
    documentoDe,
    estiloTipo,
    firmaPlan,
    formatearRango,
    moverA,
    parsearRango,
    partirDesde,
    planDe,
    reordenar,
} from '@/lib/editorHojas';
import type { DocumentoEditor, ResultadoOperacion } from '@/lib/editorHojas';
import { ErrorSolicitud, enviarJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { index as indexExpedientes } from '@/routes/rh/expedientes';
import {
    confirmar,
    vistaPrevia,
} from '@/routes/rh/expedientes/division-historico/editor';
import type {
    DocumentoPlanEditor,
    HojaEditorDivision,
    RevisionEditor,
    TipoEditor,
    VistaPreviaEditor,
} from '@/types';

/**
 * Editor de hojas del PDF histórico (docs/DIVISION_EXPEDIENTE_HISTORICO.md,
 * «Corregir hojas a mano»): miniaturas de TODAS las hojas con su
 * clasificación; RH asigna por hoja o en lote, une hojas al documento
 * anterior, parte, mueve y reordena. Todo vive en el navegador (con
 * deshacer/rehacer) hasta «Guardar», que primero muestra la vista previa.
 */
const props = defineProps<{
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        division_url: string;
    };
    division: {
        id: number;
        total_paginas: number;
        creada_en: string | null;
        revision_actual: number;
        original_url: string;
        nombre_archivo: string;
    };
    paginas: HojaEditorDivision[];
    documentos: DocumentoPlanEditor[];
    revisiones: RevisionEditor[];
    tipos: TipoEditor[];
    permisos: { guardar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Expedientes', href: indexExpedientes() },
            { title: 'Corregir hojas del PDF histórico' },
        ],
    },
});

const { confirmarAccion } = useAlertas();
const enEscritorio = useMediaQuery('(min-width: 768px)');

// ── PDF (pdf.js, una sola descarga para miniaturas y zoom) ──────────────
const urlPdf = computed(() => props.division.original_url);
const pdf = usePdfDocumento(urlPdf);
const hojasPdf = pdf.paginas;
const ANCHO_MINIATURA = computed(() => (enEscritorio.value ? 148 : 72));

function medidas(numero: number): { anchoMm: number; proporcion: number } {
    const hoja = hojasPdf.value.find((h) => h.numero === numero);

    return hoja
        ? { anchoMm: hoja.anchoMm, proporcion: hoja.altoMm / hoja.anchoMm }
        : { anchoMm: 210, proporcion: 297 / 210 };
}

// ── Estado del editor con deshacer/rehacer ──────────────────────────────
const historial = ref<DocumentoEditor[][]>([crearEstado(props.documentos)]);
const posicion = ref(0);
const estadoDocs = computed(() => historial.value[posicion.value] ?? []);
const firmaGuardada = ref(firmaPlan(historial.value[0] ?? []));
const sucio = computed(
    () => firmaPlan(estadoDocs.value) !== firmaGuardada.value,
);
const aviso = ref<string | null>(null);

/** Asignación con la que se abrió el editor, para marcar «cambiada». */
const inicial = ref(asignacionPorHoja(historial.value[0] ?? []));

function asignacionPorHoja(docs: DocumentoEditor[]): Map<number, string> {
    const mapa = new Map<number, string>();

    for (const d of docs) {
        for (const p of d.paginas) {
            mapa.set(
                p,
                d.tipoId === null ? '-' : `${d.tipoId}|${d.paginas[0]}`,
            );
        }
    }

    return mapa;
}

watch(
    // Por CONTENIDO, no por referencia: las recargas parciales del layout
    // (avisos, contadores) traen props nuevos y no deben borrar la sesión.
    () =>
        `${props.division.revision_actual}|${JSON.stringify(props.documentos)}`,
    () => {
        // Tras guardar, Inertia trae el plan vigente: se reinicia la sesión.
        historial.value = [crearEstado(props.documentos)];
        posicion.value = 0;
        firmaGuardada.value = firmaPlan(historial.value[0] ?? []);
        inicial.value = asignacionPorHoja(historial.value[0] ?? []);
        previa.value = null;
    },
);

function aplicar(resultado: ResultadoOperacion, mensaje?: string): boolean {
    if (!resultado.ok) {
        aviso.value = resultado.error;

        return false;
    }

    historial.value = [
        ...historial.value.slice(0, posicion.value + 1),
        resultado.documentos,
    ];
    posicion.value = historial.value.length - 1;
    aviso.value = mensaje ?? null;
    previa.value = null;

    return true;
}

const puedeDeshacer = computed(() => posicion.value > 0);
const puedeRehacer = computed(
    () => posicion.value < historial.value.length - 1,
);

function deshacer(): void {
    if (puedeDeshacer.value) {
        posicion.value -= 1;
        previa.value = null;
        aviso.value = 'Se deshizo el último cambio.';
    }
}

function rehacer(): void {
    if (puedeRehacer.value) {
        posicion.value += 1;
        previa.value = null;
        aviso.value = 'Se rehízo el cambio.';
    }
}

// ── Documentos: numeración, nombres, colores ────────────────────────────
const nombreTipo = computed(
    () => new Map(props.tipos.map((t) => [t.id, t.nombre] as const)),
);

function nombreDe(doc: DocumentoEditor | undefined): string {
    return doc?.tipoId != null
        ? (nombreTipo.value.get(doc.tipoId) ?? 'Documento')
        : 'Sin clasificar';
}

function numeroDoc(doc: DocumentoEditor): number {
    return estadoDocs.value.findIndex((d) => d.id === doc.id) + 1;
}

const clasificados = computed(() =>
    estadoDocs.value.filter((d) => d.tipoId !== null),
);

const destinos = computed(() =>
    clasificados.value.map((d) => ({
        value: d.id,
        label: `Doc ${numeroDoc(d)} · ${nombreDe(d)} (p. ${describirOrden(d.paginas)})`,
    })),
);

const sinClasificar = computed(() =>
    estadoDocs.value.filter((d) => d.tipoId === null).flatMap((d) => d.paginas),
);

const tiposRepetidos = computed(() => {
    const cuenta = new Map<number, number>();

    for (const d of clasificados.value) {
        cuenta.set(
            d.tipoId as number,
            (cuenta.get(d.tipoId as number) ?? 0) + 1,
        );
    }

    return [...cuenta.entries()]
        .filter(([, n]) => n > 1)
        .map(([id]) => nombreTipo.value.get(id) ?? 'Documento');
});

const infoHoja = computed(
    () => new Map(props.paginas.map((p) => [p.pagina, p] as const)),
);

function cambiada(numero: number): boolean {
    const doc = documentoDe(estadoDocs.value, numero);
    const ahora = doc
        ? doc.tipoId === null
            ? '-'
            : `${doc.tipoId}|${doc.paginas[0]}`
        : '-';

    return inicial.value.get(numero) !== ahora;
}

function detalleOcr(numero: number): string | null {
    const info = infoHoja.value.get(numero);

    if (!info) {
        return null;
    }

    const partes = [
        info.tipo_ocr ? `OCR: ${info.tipo_ocr}` : 'OCR: sin clasificar',
    ];

    if (info.motivo) {
        partes.push(info.motivo);
    }

    return partes.join(' · ');
}

// ── Selección (clic, Mayús+clic, Ctrl/Cmd+clic, rango escrito, teclado) ──
const total = computed(() => props.division.total_paginas);
const hojas = computed(() =>
    Array.from({ length: total.value }, (_, i) => i + 1),
);
const seleccion = ref<number[]>([]);
const ancla = ref<number | null>(null);
const foco = ref(1);

function rangoEntre(a: number, b: number): number[] {
    const [inicio, fin] = a <= b ? [a, b] : [b, a];

    return Array.from({ length: fin - inicio + 1 }, (_, i) => inicio + i);
}

function unir(a: number[], b: number[]): number[] {
    return [...new Set([...a, ...b])].sort((x, y) => x - y);
}

function alternar(numero: number): void {
    seleccion.value = seleccion.value.includes(numero)
        ? seleccion.value.filter((p) => p !== numero)
        : unir(seleccion.value, [numero]);
    ancla.value = numero;
}

function clicHoja(numero: number, e: MouseEvent): void {
    foco.value = numero;

    if (e.shiftKey && ancla.value !== null) {
        const rango = rangoEntre(ancla.value, numero);
        seleccion.value =
            e.ctrlKey || e.metaKey ? unir(seleccion.value, rango) : rango;

        return;
    }

    // En móvil/tablet (toque) cada toque suma o quita, sin modificadores.
    if (e.ctrlKey || e.metaKey || !enEscritorio.value) {
        alternar(numero);

        return;
    }

    seleccion.value =
        seleccion.value.length === 1 && seleccion.value[0] === numero
            ? []
            : [numero];
    ancla.value = numero;
}

function seleccionarRango(paginas: number[]): void {
    seleccion.value = paginas;
    ancla.value = paginas[0] ?? null;

    if (paginas[0]) {
        enfocar(paginas[0]);
    }
}

// «Corregir hojas» desde una parte de la división (?hojas=3,4): esas hojas
// llegan ya seleccionadas para asignarlas o separarlas de inmediato.
onMounted(() => {
    const pedidas = new URLSearchParams(window.location.search).get('hojas');

    if (!pedidas) {
        return;
    }

    const { paginas, error } = parsearRango(pedidas, total.value);

    if (!error && paginas.length) {
        seleccionarRango(paginas);
        aviso.value = `Hojas ${formatearRango(paginas)} seleccionadas: elige a qué documento pertenece cada una.`;
    }
});

function seleccionarDocumento(doc: DocumentoEditor): void {
    seleccionarRango([...doc.paginas].sort((a, b) => a - b));
}

const rejilla = ref<HTMLElement | null>(null);

function columnas(): number {
    if (!rejilla.value) {
        return 1;
    }

    const plantilla = getComputedStyle(rejilla.value).gridTemplateColumns;

    return Math.max(1, plantilla.split(' ').filter(Boolean).length);
}

function enfocar(numero: number): void {
    foco.value = Math.min(Math.max(1, numero), total.value);
    void nextTick(() =>
        document
            .getElementById(`hoja-${foco.value}`)
            ?.focus({ preventScroll: false }),
    );
}

function teclaRejilla(e: KeyboardEvent): void {
    const pasos: Record<string, number> = {
        ArrowRight: 1,
        ArrowLeft: -1,
        ArrowDown: columnas(),
        ArrowUp: -columnas(),
    };

    if (e.key in pasos) {
        e.preventDefault();
        const destino = Math.min(
            Math.max(1, foco.value + pasos[e.key]),
            total.value,
        );

        if (e.shiftKey) {
            ancla.value ??= foco.value;
            seleccion.value = rangoEntre(ancla.value, destino);
        }

        enfocar(destino);
    } else if (e.key === 'Home' || e.key === 'End') {
        e.preventDefault();
        enfocar(e.key === 'Home' ? 1 : total.value);
    } else if (e.key === ' ') {
        e.preventDefault();
        alternar(foco.value);
    } else if (e.key === 'Enter') {
        e.preventDefault();
        zoom.value = foco.value;
    } else if (e.key === 'Escape') {
        seleccion.value = [];
    } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'a') {
        e.preventDefault();
        seleccion.value = [...hojas.value];
    }
}

// ── Acciones ─────────────────────────────────────────────────────────────
const sheetAbierta = ref(false);

function tras(ok: boolean): void {
    if (ok) {
        sheetAbierta.value = false;
    }
}

function accionAsignar(tipoId: number | null, aparte: boolean): void {
    const paginas = seleccion.value;
    const nombre =
        tipoId === null
            ? 'Sin clasificar'
            : (nombreTipo.value.get(tipoId) ?? '');

    tras(
        aplicar(
            asignarTipo(estadoDocs.value, paginas, tipoId, aparte),
            `Hojas ${formatearRango(paginas)} → ${nombre}.`,
        ),
    );
}

function accionContinuar(): void {
    const paginas = seleccion.value;
    const anterior = Math.min(...paginas) - 1;
    const destino = documentoDe(estadoDocs.value, anterior);

    tras(
        aplicar(
            continuarAnterior(estadoDocs.value, paginas),
            `Hojas ${formatearRango(paginas)} unidas a ${nombreDe(destino)} (desde la hoja ${anterior}).`,
        ),
    );
}

function accionPartir(): void {
    const [pagina] = seleccion.value;

    if (pagina !== undefined) {
        tras(
            aplicar(
                partirDesde(estadoDocs.value, pagina),
                `Nuevo documento desde la hoja ${pagina}.`,
            ),
        );
    }
}

function accionMover(destinoId: string): void {
    const destino = estadoDocs.value.find((d) => d.id === destinoId);
    const paginas = seleccion.value;

    tras(
        aplicar(
            moverA(estadoDocs.value, paginas, destinoId),
            `Hojas ${formatearRango(paginas)} movidas a ${nombreDe(destino)}.`,
        ),
    );
}

function accionReordenar(pagina: number, delta: -1 | 1): void {
    aplicar(reordenar(estadoDocs.value, pagina, delta));
}

// ── Zoom ─────────────────────────────────────────────────────────────────
const zoom = ref<number | null>(null);

function etiquetaHoja(numero: number): string {
    const doc = documentoDe(estadoDocs.value, numero);

    return doc
        ? `${nombreDe(doc)} · Doc ${numeroDoc(doc)} (hoja ${doc.paginas.indexOf(numero) + 1} de ${doc.paginas.length})`
        : 'Sin clasificar';
}

// ── Vista previa y guardar ───────────────────────────────────────────────
const previa = ref<VistaPreviaEditor | null>(null);
const previaAbierta = ref(false);
const cargandoPrevia = ref(false);
const errorPrevia = ref<string | null>(null);
const guardando = ref(false);

async function abrirVistaPrevia(): Promise<void> {
    previaAbierta.value = true;
    sheetAbierta.value = false;

    if (previa.value) {
        return;
    }

    cargandoPrevia.value = true;
    errorPrevia.value = null;

    try {
        previa.value = await enviarJson<VistaPreviaEditor>(
            'POST',
            vistaPrevia(props.division.id).url,
            { documentos: planDe(estadoDocs.value) },
        );
    } catch (e) {
        errorPrevia.value =
            e instanceof ErrorSolicitud
                ? e.message
                : 'No se pudo armar la vista previa. Intenta de nuevo.';
    } finally {
        cargandoPrevia.value = false;
    }
}

function guardar(): void {
    guardando.value = true;
    router.post(
        confirmar(props.division.id).url,
        {
            documentos: planDe(estadoDocs.value),
            revision_base: props.division.revision_actual,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                previaAbierta.value = false;
                seleccion.value = [];
                aviso.value = null;
            },
            onError: (errores) => {
                errorPrevia.value =
                    Object.values(errores)[0] ?? 'No se pudo guardar.';
                previa.value = null;
            },
            onFinish: () => (guardando.value = false),
        },
    );
}

async function volver(): Promise<void> {
    if (
        sucio.value &&
        !(await confirmarAccion(
            'Salir sin guardar',
            'Tienes cambios sin guardar en las hojas. Si sales, se pierden.',
            'Sí, salir',
        ))
    ) {
        return;
    }

    firmaGuardada.value = firmaPlan(estadoDocs.value);
    router.visit(props.colaborador.division_url);
}

useEventListener(window, 'beforeunload', (e: BeforeUnloadEvent) => {
    if (sucio.value && !guardando.value) {
        e.preventDefault();
    }
});

function enCampo(e: KeyboardEvent): boolean {
    const t = e.target;

    return (
        t instanceof HTMLInputElement ||
        t instanceof HTMLTextAreaElement ||
        (t instanceof HTMLElement && t.isContentEditable) ||
        zoom.value !== null ||
        previaAbierta.value
    );
}

onKeyStroke(['z', 'Z', 'y', 'Y'], (e) => {
    if (!(e.ctrlKey || e.metaKey) || enCampo(e)) {
        return;
    }

    e.preventDefault();

    if (e.key.toLowerCase() === 'y' || e.shiftKey) {
        rehacer();
    } else {
        deshacer();
    }
});
</script>

<template>
    <Head title="Corregir hojas del PDF histórico" />

    <div
        class="pagina-ancha flex flex-col gap-4"
        :class="!enEscritorio && 'pb-44'"
    >
        <CrudPageHeader
            titulo="Corregir hojas del PDF histórico"
            :descripcion="`${colaborador.nombre}${colaborador.numero_empleado ? ` · ${colaborador.numero_empleado}` : ''} · ${division.nombre_archivo} (${division.total_paginas} hojas)`"
            :icono="ListChecks"
            detalle
        >
            <template #default>
                <Button variant="outline" @click="volver">
                    <ArrowLeft class="size-4" /> Volver a la división
                </Button>
            </template>
        </CrudPageHeader>

        <p class="text-sm text-muted-foreground">
            Elige hojas y asígnales el documento al que pertenecen. Nada cambia
            en el expediente hasta que revises la vista previa y guardes; el PDF
            original nunca se modifica.
        </p>

        <!-- Barra de sesión: deshacer/rehacer, estado, vista previa/guardar -->
        <div
            class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card p-2 shadow-sm"
        >
            <Button
                variant="ghost"
                size="sm"
                :disabled="!puedeDeshacer"
                aria-keyshortcuts="Control+Z"
                @click="deshacer"
            >
                <Undo2 class="size-4" /> Deshacer
            </Button>
            <Button
                variant="ghost"
                size="sm"
                :disabled="!puedeRehacer"
                aria-keyshortcuts="Control+Y"
                @click="rehacer"
            >
                <Redo2 class="size-4" /> Rehacer
            </Button>
            <span
                class="min-w-0 flex-1 truncate px-1 text-sm"
                :class="aviso ? 'text-foreground' : 'text-muted-foreground'"
                aria-live="polite"
                role="status"
            >
                {{
                    aviso ??
                    (sucio
                        ? 'Cambios sin guardar.'
                        : division.revision_actual
                          ? `Guardado (revisión ${division.revision_actual}).`
                          : 'Sin cambios: así lo dejó el OCR.')
                }}
            </span>
            <Badge v-if="sucio" variant="warning">Sin guardar</Badge>
            <Button
                variant="outline"
                size="sm"
                :disabled="!sucio"
                @click="abrirVistaPrevia"
            >
                <Eye class="size-4" /> Vista previa
            </Button>
            <Button
                v-if="permisos.guardar"
                size="sm"
                :disabled="!sucio"
                @click="abrirVistaPrevia"
            >
                <Save class="size-4" /> Guardar
            </Button>
        </div>

        <div
            class="grid gap-4 md:grid-cols-[minmax(0,1fr)_22rem] 2xl:grid-cols-[minmax(0,1fr)_26rem]"
        >
            <!-- Hojas -->
            <section
                aria-labelledby="titulo-hojas"
                class="flex min-w-0 flex-col gap-3"
            >
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <h2 id="titulo-hojas" class="text-base font-semibold">
                        Hojas ({{ total }})
                    </h2>
                    <span
                        v-if="sinClasificar.length"
                        class="rounded-full border border-dashed border-warning/60 bg-warning-soft px-2 py-0.5 text-xs"
                    >
                        Sin clasificar: {{ formatearRango(sinClasificar) }}
                    </span>
                    <span
                        v-if="pdf.cargando.value"
                        class="flex items-center gap-1 text-xs text-muted-foreground"
                    >
                        <Loader2 class="size-3 animate-spin" /> Abriendo PDF…
                    </span>
                    <span
                        v-else-if="pdf.fallo.value"
                        class="text-xs text-destructive"
                        role="alert"
                        >No se pudo abrir el PDF para las miniaturas.</span
                    >
                </div>

                <div
                    id="rejilla-hojas"
                    ref="rejilla"
                    role="listbox"
                    aria-multiselectable="true"
                    aria-label="Hojas del PDF histórico. Flechas para moverte, Espacio para seleccionar, Mayús+flechas para un rango, Enter para ver en grande."
                    class="grid gap-3"
                    :class="
                        enEscritorio
                            ? 'h-[calc(100svh-16rem)] min-h-[26rem] grid-cols-[repeat(auto-fill,minmax(10.5rem,1fr))] content-start overflow-y-auto p-1.5 pr-2'
                            : 'grid-cols-1'
                    "
                    @keydown="teclaRejilla"
                >
                    <div
                        v-for="n in hojas"
                        :id="`hoja-${n}`"
                        :key="n"
                        role="option"
                        :aria-selected="seleccion.includes(n)"
                        :aria-label="`Hoja ${n}: ${etiquetaHoja(n)}`"
                        :tabindex="n === foco ? 0 : -1"
                        class="group relative flex tarjeta-interactiva cursor-pointer rounded-xl border-2 p-2 outline-none select-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="[
                            estiloTipo(
                                documentoDe(estadoDocs, n)?.tipoId ?? null,
                                tipos,
                            ),
                            seleccion.includes(n) &&
                                'ring-2 ring-primary ring-offset-2 ring-offset-background',
                            enEscritorio
                                ? 'flex-col items-center gap-2'
                                : 'flex-row items-center gap-3',
                        ]"
                        @click="clicHoja(n, $event)"
                        @focus="foco = n"
                    >
                        <span
                            v-if="seleccion.includes(n)"
                            class="absolute top-1.5 left-1.5 z-10 flex size-6 items-center justify-center rounded-full bg-primary text-primary-foreground shadow"
                            aria-hidden="true"
                        >
                            <Check class="size-4" />
                        </span>
                        <MiniaturaHoja
                            :numero="n"
                            :ancho="ANCHO_MINIATURA"
                            :proporcion="medidas(n).proporcion"
                            :ancho-mm="medidas(n).anchoMm"
                            :dibujar="pdf.dibujar"
                            :listo="hojasPdf.length > 0"
                        />
                        <div
                            class="flex min-w-0 flex-1 flex-col gap-1"
                            :class="
                                enEscritorio &&
                                'w-full items-center text-center'
                            "
                        >
                            <span class="text-sm font-semibold tabular-nums"
                                >Hoja {{ n }}</span
                            >
                            <span
                                class="max-w-full truncate rounded-full bg-card/80 px-2 py-0.5 text-xs font-medium"
                                :title="nombreDe(documentoDe(estadoDocs, n))"
                            >
                                {{ nombreDe(documentoDe(estadoDocs, n)) }}
                            </span>
                            <span
                                v-if="
                                    documentoDe(estadoDocs, n)?.tipoId != null
                                "
                                class="text-[11px] text-muted-foreground tabular-nums"
                            >
                                Doc
                                {{
                                    numeroDoc(
                                        documentoDe(
                                            estadoDocs,
                                            n,
                                        ) as DocumentoEditor,
                                    )
                                }}
                                ·
                                {{
                                    (
                                        documentoDe(
                                            estadoDocs,
                                            n,
                                        ) as DocumentoEditor
                                    ).paginas.indexOf(n) + 1
                                }}/{{
                                    (
                                        documentoDe(
                                            estadoDocs,
                                            n,
                                        ) as DocumentoEditor
                                    ).paginas.length
                                }}
                            </span>
                            <span class="flex flex-wrap justify-center gap-1">
                                <Badge
                                    v-if="cambiada(n)"
                                    variant="info"
                                    class="text-[10px]"
                                    >Cambiada</Badge
                                >
                                <Badge
                                    v-else-if="infoHoja.get(n)?.manual"
                                    variant="secondary"
                                    class="text-[10px]"
                                    ><UserPen /> Corregida por RH</Badge
                                >
                            </span>
                            <span
                                v-if="!enEscritorio"
                                class="line-clamp-2 text-[11px] text-muted-foreground"
                                >{{ detalleOcr(n) }}</span
                            >
                        </div>
                        <Button
                            size="icon"
                            variant="ghost"
                            class="shrink-0 bg-card/80"
                            :class="
                                enEscritorio &&
                                'absolute top-1.5 right-1.5 size-7 opacity-70 group-hover:opacity-100 focus-visible:opacity-100'
                            "
                            :aria-label="`Ver la hoja ${n} en grande`"
                            tabindex="-1"
                            @click.stop="zoom = n"
                        >
                            <Search class="size-4" />
                        </Button>
                    </div>
                </div>
            </section>

            <!-- Panel lateral -->
            <!-- En escritorio, hojas y panel tienen su propio scroll: la barra
                 de sesión y las acciones siempre quedan a la vista. -->
            <aside
                class="flex min-w-0 flex-col gap-4 md:mt-9 md:h-[calc(100svh-16rem)] md:min-h-[26rem] md:overflow-y-auto md:pr-1"
            >
                <div v-if="enEscritorio" class="flex flex-col gap-4">
                    <section
                        class="rounded-2xl border bg-card p-4"
                        aria-label="Acciones sobre las hojas seleccionadas"
                    >
                        <PanelAccionesHojas
                            :seleccion="seleccion"
                            :total="total"
                            :tipos="tipos"
                            :destinos="destinos"
                            @asignar="accionAsignar"
                            @continuar="accionContinuar"
                            @partir="accionPartir"
                            @mover="accionMover"
                            @seleccionar-rango="seleccionarRango"
                            @limpiar="seleccion = []"
                        />
                    </section>
                </div>

                <section
                    class="flex flex-col gap-2 rounded-2xl border bg-card p-4"
                    aria-labelledby="titulo-documentos"
                >
                    <h2 id="titulo-documentos" class="text-base font-semibold">
                        Documentos resultantes ({{ estadoDocs.length }})
                    </h2>
                    <p
                        v-if="tiposRepetidos.length"
                        class="rounded-xl border border-warning/40 bg-warning-soft p-2 text-xs"
                    >
                        Tipo repetido: {{ tiposRepetidos.join(', ') }}. El
                        expediente guarda una sola versión por documento: solo
                        se cargará el primero.
                    </p>
                    <ol class="flex flex-col gap-2">
                        <li
                            v-for="(d, i) in estadoDocs"
                            :key="d.id"
                            class="rounded-xl border-2 p-2.5 text-sm"
                            :class="
                                d.tipoId === null
                                    ? ESTILO_SIN_CLASIFICAR
                                    : estiloTipo(d.tipoId, tipos)
                            "
                        >
                            <div class="flex items-start gap-2">
                                <span
                                    class="mt-0.5 w-9 shrink-0 text-xs text-muted-foreground tabular-nums"
                                    >Doc {{ i + 1 }}</span
                                >
                                <div class="min-w-0 flex-1">
                                    <p class="truncate font-medium">
                                        {{ nombreDe(d) }}
                                    </p>
                                    <p class="text-xs text-muted-foreground">
                                        Hojas {{ describirOrden(d.paginas) }} ·
                                        {{ d.paginas.length }}
                                        {{
                                            d.paginas.length === 1
                                                ? 'página'
                                                : 'páginas'
                                        }}
                                    </p>
                                </div>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    class="h-7 bg-card/70 px-2 text-xs"
                                    @click="seleccionarDocumento(d)"
                                    >Seleccionar</Button
                                >
                            </div>
                            <ul
                                v-if="d.tipoId !== null && d.paginas.length > 1"
                                class="mt-2 flex flex-wrap gap-1"
                                :aria-label="`Orden de las hojas del documento ${i + 1}`"
                            >
                                <li
                                    v-for="(p, j) in d.paginas"
                                    :key="p"
                                    class="flex items-center gap-0.5 rounded-lg bg-card/80 py-0.5 pr-0.5 pl-2 text-xs tabular-nums"
                                >
                                    {{ p }}
                                    <button
                                        type="button"
                                        class="rounded p-0.5 hover:bg-muted disabled:opacity-30"
                                        :disabled="j === 0"
                                        :aria-label="`Mover la hoja ${p} antes dentro del documento`"
                                        @click="accionReordenar(p, -1)"
                                    >
                                        <ChevronLeft class="size-3" />
                                    </button>
                                    <button
                                        type="button"
                                        class="rounded p-0.5 hover:bg-muted disabled:opacity-30"
                                        :disabled="j === d.paginas.length - 1"
                                        :aria-label="`Mover la hoja ${p} después dentro del documento`"
                                        @click="accionReordenar(p, 1)"
                                    >
                                        <ChevronRight class="size-3" />
                                    </button>
                                </li>
                            </ul>
                        </li>
                    </ol>
                </section>

                <details
                    v-if="revisiones.length"
                    class="rounded-2xl border bg-card p-4 text-sm"
                >
                    <summary
                        class="flex cursor-pointer items-center gap-2 font-medium"
                    >
                        <History class="size-4" /> Correcciones guardadas ({{
                            revisiones.length
                        }})
                    </summary>
                    <ul class="mt-3 flex flex-col gap-3">
                        <li
                            v-for="r in revisiones"
                            :key="r.version"
                            class="border-t pt-2"
                        >
                            <p class="font-medium">
                                Revisión {{ r.version }} ·
                                {{ r.cambios }} hoja(s)
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ r.por ?? 'Sistema' }} ·
                                {{
                                    r.en
                                        ? new Date(r.en).toLocaleString('es-MX')
                                        : ''
                                }}
                            </p>
                            <ul class="mt-1 flex flex-col gap-0.5 text-xs">
                                <li v-for="c in r.detalle" :key="c.pagina">
                                    Hoja {{ c.pagina }}: {{ c.de }} → {{ c.a }}
                                </li>
                            </ul>
                        </li>
                    </ul>
                </details>
            </aside>
        </div>
    </div>

    <!-- Móvil: barra de acción inferior + hoja con las acciones -->
    <div
        v-if="!enEscritorio"
        class="fixed inset-x-0 bottom-16 z-30 flex items-center gap-2 border-t bg-card px-4 py-3 shadow-lg sm:bottom-0"
    >
        <span class="min-w-0 flex-1 truncate text-sm" aria-live="polite">
            {{
                seleccion.length
                    ? `${seleccion.length} seleccionada(s): ${formatearRango(seleccion)}`
                    : 'Toca hojas para seleccionarlas'
            }}
        </span>
        <Button variant="outline" @click="sheetAbierta = true">Acciones</Button>
        <Button
            v-if="permisos.guardar"
            :disabled="!sucio"
            aria-label="Guardar"
            @click="abrirVistaPrevia"
        >
            <Save class="size-4" />
        </Button>
    </div>

    <Sheet v-model:open="sheetAbierta">
        <SheetContent side="bottom" class="max-h-[85svh] overflow-y-auto">
            <SheetHeader>
                <SheetTitle>Acciones sobre las hojas</SheetTitle>
                <SheetDescription
                    >Asigna, une o parte documentos con las hojas
                    seleccionadas.</SheetDescription
                >
            </SheetHeader>
            <div class="px-4 pb-6">
                <PanelAccionesHojas
                    :seleccion="seleccion"
                    :total="total"
                    :tipos="tipos"
                    :destinos="destinos"
                    @asignar="accionAsignar"
                    @continuar="accionContinuar"
                    @partir="accionPartir"
                    @mover="accionMover"
                    @seleccionar-rango="seleccionarRango"
                    @limpiar="seleccion = []"
                />
            </div>
        </SheetContent>
    </Sheet>

    <ZoomHojaDialog
        :numero="zoom"
        :total="total"
        :hojas="hojasPdf"
        :dibujar="pdf.dibujar"
        :clasificacion="zoom ? etiquetaHoja(zoom) : ''"
        :detalle="zoom ? detalleOcr(zoom) : null"
        :seleccionada="zoom !== null && seleccion.includes(zoom)"
        @cerrar="zoom = null"
        @ir="(n) => (zoom = n)"
        @alternar="alternar"
    />

    <VistaPreviaHojasDialog
        :abierto="previaAbierta"
        :previa="previa"
        :cargando="cargandoPrevia"
        :error="errorPrevia"
        :puede-guardar="permisos.guardar"
        :guardando="guardando"
        @cerrar="previaAbierta = false"
        @confirmar="guardar"
    />
</template>
