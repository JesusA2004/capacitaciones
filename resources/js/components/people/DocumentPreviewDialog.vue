<script setup lang="ts">
import {
    ChevronLeft,
    ChevronRight,
    Download,
    ExternalLink,
    FileQuestion,
    FileSpreadsheet,
    FileText,
    FileWarning,
    Image as IconoImagen,
    Maximize,
    Music,
    Video,
    X,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { useResizeObserver } from '@vueuse/core';
import type { Component } from 'vue';
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    claseDeArchivo,
    extensionDe,
    formatearTamano,
    formatoDeArchivo,
} from '@/lib/archivos';
import { cn } from '@/lib/utils';
import type { ArchivoPrevisualizable, ClaseArchivoVisor } from '@/types';

/**
 * Visor único de archivos del NAS (CV, psicométricos, socioeconómico,
 * referencias, actas, adjuntos de solicitudes…). Nunca recibe una ruta
 * física ni una URL directa al NAS — solo endpoints protegidos que sirven
 * el archivo con `Content-Disposition: inline` y su Content-Type real (la
 * autorización vive en el backend, ver CLAUDE.md). Este componente solo
 * decide CÓMO mostrarlo:
 *
 * - PDF/texto en iframe a altura completa (visor nativo del navegador).
 * - Imágenes con ajuste a pantalla y zoom (botones, Ctrl+rueda, doble clic).
 * - Video/audio con reproductor (Range real en el backend para adelantar).
 * - Word/Excel/otros: aviso amable con botón Descargar.
 *
 * Con `archivos` (varios) navega con anterior/siguiente, flechas del
 * teclado o la tira inferior. Los props sueltos (`previewUrl`, `nombre`…)
 * siguen funcionando para un solo archivo.
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        /** Galería de archivos; si se omite se usan los props sueltos. */
        archivos?: ArchivoPrevisualizable[] | null;
        /** Archivo con el que abre la galería. */
        indice?: number;
        /** URL protegida que sirve el archivo inline (un solo archivo). */
        previewUrl?: string | null;
        /** URL para forzar descarga (attachment); si se omite, usa previewUrl. */
        downloadUrl?: string | null;
        nombre?: string;
        tipo?: string | null;
        mime?: string | null;
        tamano?: number | null;
        version?: number | null;
        estado?: string | null;
        subidoPor?: string | null;
        fecha?: string | null;
    }>(),
    {
        archivos: null,
        indice: 0,
        previewUrl: null,
        downloadUrl: null,
        nombre: '',
        tipo: null,
        mime: null,
        tamano: null,
        version: null,
        estado: null,
        subidoPor: null,
        fecha: null,
    },
);

const emit = defineEmits<{
    'update:open': [value: boolean];
    'update:indice': [value: number];
}>();

const lista = computed<ArchivoPrevisualizable[]>(() => {
    if (props.archivos?.length) {
        return props.archivos;
    }

    if (!props.previewUrl) {
        return [];
    }

    return [
        {
            clave: 'unico',
            nombre: props.nombre || 'Documento',
            previewUrl: props.previewUrl,
            downloadUrl: props.downloadUrl,
            tipo: props.tipo,
            mime: props.mime,
            tamano: props.tamano,
            version: props.version,
            estado: props.estado,
            subidoPor: props.subidoPor,
            fecha: props.fecha,
        },
    ];
});

const indiceActual = ref(0);
const total = computed(() => lista.value.length);
const actual = computed<ArchivoPrevisualizable | null>(
    () => lista.value[indiceActual.value] ?? null,
);

function acotar(indice: number): number {
    return Math.min(Math.max(indice, 0), Math.max(total.value - 1, 0));
}

watch(
    () => [props.open, props.indice] as const,
    ([abierto, indice]) => {
        if (abierto) {
            indiceActual.value = acotar(indice);
        }
    },
    { immediate: true },
);

watch(total, () => {
    indiceActual.value = acotar(indiceActual.value);
});

function irA(indice: number) {
    if (total.value < 2) {
        return;
    }

    // Circular: después del último vuelve al primero.
    indiceActual.value = (indice + total.value) % total.value;
    emit('update:indice', indiceActual.value);
}

const anterior = () => irA(indiceActual.value - 1);
const siguiente = () => irA(indiceActual.value + 1);

// ── Tipo de archivo ──────────────────────────────────────────────────────
/** Clase detectada por el servidor cuando nombre/mime no alcanzaron. */
const claseDetectada = ref<ClaseArchivoVisor | null>(null);

const clase = computed<ClaseArchivoVisor>(() => {
    if (!actual.value) {
        return 'otro';
    }

    const local = claseDeArchivo(actual.value);

    return local === 'otro' ? (claseDetectada.value ?? 'otro') : local;
});

const embebible = computed(() =>
    ['pdf', 'imagen', 'video', 'audio', 'texto'].includes(clase.value),
);

const formato = computed(() =>
    actual.value ? formatoDeArchivo(actual.value) : '',
);

const ICONOS: Record<ClaseArchivoVisor, Component> = {
    pdf: FileText,
    imagen: IconoImagen,
    video: Video,
    audio: Music,
    texto: FileText,
    office: FileSpreadsheet,
    otro: FileQuestion,
};

const TONOS: Record<ClaseArchivoVisor, string> = {
    pdf: 'bg-danger-soft text-danger',
    imagen: 'bg-info-soft text-info',
    video: 'bg-salvia text-[var(--mrl-petroleo)]',
    audio: 'bg-salvia text-[var(--mrl-petroleo)]',
    texto: 'bg-crema text-bronce',
    office: 'bg-success-soft text-success',
    otro: 'bg-crema text-bronce',
};

function iconoDe(archivo: ArchivoPrevisualizable): Component {
    const c = claseDeArchivo(archivo);

    if (
        c === 'office' &&
        /^docx?$|^odt$|^rtf$/.test(extensionDe(archivo.nombre))
    ) {
        return FileText;
    }

    return ICONOS[c];
}

const meta = computed(() => {
    const a = actual.value;

    if (!a) {
        return [];
    }

    return [
        a.etiqueta,
        formato.value,
        formatearTamano(a.tamano),
        a.version ? `Versión ${a.version}` : null,
        a.estado ? a.estado.replace(/_/g, ' ') : null,
        a.subidoPor ? `Subido por ${a.subidoPor}` : null,
        a.fecha ? fechaLegible(a.fecha) : null,
    ].filter((v): v is string => Boolean(v));
});

function fechaLegible(valor: string): string {
    // ISO del backend → "12 oct 2026"; cualquier otro texto se respeta.
    if (!/^\d{4}-\d{2}-\d{2}/.test(valor)) {
        return valor;
    }

    const fecha = new Date(valor);

    return Number.isNaN(fecha.getTime())
        ? valor
        : fecha.toLocaleDateString('es-MX', {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          });
}

const urlDescarga = computed(
    () => actual.value?.downloadUrl ?? actual.value?.previewUrl ?? null,
);

// ── Estado de carga ──────────────────────────────────────────────────────
type Estado = 'cargando' | 'listo' | 'error';

const estadoCarga = ref<Estado>('cargando');
const mensajeError = ref('');
/** Tamaño real de la imagen y zoom (null = ajustar a la pantalla). */
const tamanoNatural = ref<{ ancho: number; alto: number } | null>(null);
const escala = ref<number | null>(null);
let sondeo: AbortController | null = null;

function cancelarSondeo() {
    sondeo?.abort();
    sondeo = null;
}

/**
 * Pide solo el primer byte (Range) para conocer estatus y Content-Type sin
 * descargar el archivo: sirve para explicar un error (403/404) y para
 * reconocer archivos sin extensión.
 */
async function sondear(
    url: string,
): Promise<{ estatus: number; tipo: string } | null> {
    cancelarSondeo();
    const control = new AbortController();
    sondeo = control;

    try {
        const respuesta = await fetch(url, {
            headers: { Range: 'bytes=0-0' },
            credentials: 'same-origin',
            signal: control.signal,
        });
        void respuesta.body?.cancel();

        return {
            estatus: respuesta.status,
            tipo: (respuesta.headers.get('Content-Type') ?? '').toLowerCase(),
        };
    } catch {
        return null;
    }
}

async function marcarError(motivo?: string) {
    estadoCarga.value = 'error';
    mensajeError.value =
        motivo ?? 'No se pudo cargar la vista previa de este archivo.';

    const url = actual.value?.previewUrl;

    if (!url || motivo) {
        return;
    }

    const clave = actual.value?.clave;
    const resultado = await sondear(url);

    if (!resultado || actual.value?.clave !== clave) {
        return;
    }

    if (resultado.estatus === 403 || resultado.estatus === 401) {
        mensajeError.value = 'No tienes permiso para ver este archivo.';
    } else if (resultado.estatus === 404) {
        mensajeError.value =
            'El archivo ya no está disponible en el almacenamiento.';
    } else if (clase.value === 'video' || clase.value === 'audio') {
        mensajeError.value =
            'Este navegador no puede reproducir el formato del archivo. Descárgalo para verlo.';
    }
}

watch(
    () => [props.open, actual.value?.clave] as const,
    async ([abierto]) => {
        cancelarSondeo();
        claseDetectada.value = null;
        mensajeError.value = '';
        escala.value = null;
        tamanoNatural.value = null;

        if (!abierto || !actual.value) {
            return;
        }

        estadoCarga.value = embebible.value ? 'cargando' : 'listo';

        if (claseDeArchivo(actual.value) !== 'otro') {
            return;
        }

        // Sin extensión ni mime útil: que el servidor diga qué es.
        const clave = actual.value.clave;
        const resultado = await sondear(actual.value.previewUrl);

        if (!resultado || actual.value?.clave !== clave) {
            return;
        }

        if (resultado.estatus >= 400) {
            void marcarError();

            return;
        }

        const detectada = claseDeArchivo({
            nombre: '',
            mime: resultado.tipo.split(';')[0],
        });

        if (detectada !== 'otro' && detectada !== 'office') {
            claseDetectada.value = detectada;
            estadoCarga.value = 'cargando';
        }
    },
    { immediate: true },
);

onBeforeUnmount(cancelarSondeo);

function alCargarIframe(evento: Event) {
    const marco = evento.target as HTMLIFrameElement;

    try {
        // Mismo origen: si el servidor respondió una página de error (HTML)
        // en vez del PDF, se muestra el aviso en lugar de esa página.
        const tipo = marco.contentDocument?.contentType ?? '';

        if (clase.value === 'pdf' && tipo.startsWith('text/html')) {
            void marcarError();

            return;
        }
    } catch {
        // Visor de PDF aislado del navegador: no se puede inspeccionar.
    }

    estadoCarga.value = 'listo';
}

// ── Zoom de imágenes ─────────────────────────────────────────────────────
const escenario = ref<HTMLElement | null>(null);
const tamanoEscenario = ref({ ancho: 0, alto: 0 });

useResizeObserver(escenario, (entradas) => {
    const caja = entradas[0]?.contentRect;

    if (caja) {
        tamanoEscenario.value = { ancho: caja.width, alto: caja.height };
    }
});

const MARGEN = 32;

const escalaAjuste = computed(() => {
    const natural = tamanoNatural.value;
    const { ancho, alto } = tamanoEscenario.value;

    if (!natural || !ancho || !alto) {
        return 1;
    }

    return Math.min(
        (ancho - MARGEN) / natural.ancho,
        (alto - MARGEN) / natural.alto,
        1,
    );
});

const escalaVisible = computed(() => escala.value ?? escalaAjuste.value);
const porcentaje = computed(() => `${Math.round(escalaVisible.value * 100)}%`);

const estiloImagen = computed(() => {
    const natural = tamanoNatural.value;

    if (escala.value === null || !natural) {
        return undefined;
    }

    return {
        width: `${natural.ancho * escala.value}px`,
        height: `${natural.alto * escala.value}px`,
    };
});

function alCargarImagen(evento: Event) {
    const img = evento.target as HTMLImageElement;
    tamanoNatural.value = {
        ancho: img.naturalWidth || 1,
        alto: img.naturalHeight || 1,
    };
    estadoCarga.value = 'listo';
}

function cambiarEscala(factor: number) {
    escala.value = Math.min(Math.max(escalaVisible.value * factor, 0.1), 8);
}

const acercar = () => cambiarEscala(1.25);
const alejar = () => cambiarEscala(0.8);
const ajustar = () => (escala.value = null);
const tamanoReal = () => (escala.value = 1);

function alternarZoom() {
    if (escala.value === null) {
        escala.value = escalaAjuste.value < 1 ? 1 : 2;
    } else {
        escala.value = null;
    }
}

function alRueda(evento: WheelEvent) {
    if (clase.value !== 'imagen' || !(evento.ctrlKey || evento.metaKey)) {
        return;
    }

    evento.preventDefault();
    cambiarEscala(evento.deltaY < 0 ? 1.1 : 0.9);
}

// ── Teclado y tira de archivos ───────────────────────────────────────────
function alTeclado(evento: KeyboardEvent) {
    const destino = evento.target as HTMLElement | null;

    // El reproductor usa las flechas para adelantar/retroceder.
    if (destino?.closest('video, audio, input, textarea')) {
        return;
    }

    if (evento.key === 'ArrowLeft' && total.value > 1) {
        evento.preventDefault();
        anterior();
    } else if (evento.key === 'ArrowRight' && total.value > 1) {
        evento.preventDefault();
        siguiente();
    } else if (clase.value === 'imagen' && estadoCarga.value === 'listo') {
        if (evento.key === '+' || evento.key === '=') {
            evento.preventDefault();
            acercar();
        } else if (evento.key === '-') {
            evento.preventDefault();
            alejar();
        } else if (evento.key === '0') {
            evento.preventDefault();
            ajustar();
        }
    }
}

const tira = ref<HTMLElement | null>(null);

watch(indiceActual, async (indice) => {
    await nextTick();
    tira.value
        ?.querySelector<HTMLElement>(`[data-indice="${indice}"]`)
        ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent
            :show-close-button="false"
            class="flex h-[calc(100svh-1rem)] w-[calc(100vw-1rem)] max-w-none flex-col gap-0 overflow-hidden rounded-2xl border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-0 sm:h-[92svh] sm:w-[94vw] sm:max-w-[1400px]"
            @keydown="alTeclado"
        >
            <!-- Encabezado: qué archivo es y qué se puede hacer con él. -->
            <header
                class="flex items-center gap-3 border-b border-[var(--mrl-borde)] px-3 py-2.5 sm:px-5 sm:py-3"
            >
                <span
                    v-if="actual"
                    :class="
                        cn(
                            'hidden size-10 shrink-0 items-center justify-center rounded-xl sm:flex',
                            TONOS[clase],
                        )
                    "
                    aria-hidden="true"
                >
                    <component :is="iconoDe(actual)" class="size-5" />
                </span>

                <div class="min-w-0 flex-1">
                    <DialogTitle
                        class="truncate text-sm font-semibold sm:text-base"
                        :title="actual?.nombre"
                    >
                        {{ actual?.nombre ?? 'Vista previa' }}
                    </DialogTitle>
                    <DialogDescription
                        class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs text-muted-foreground"
                    >
                        <template v-for="(dato, i) in meta" :key="i">
                            <span
                                v-if="i > 0"
                                aria-hidden="true"
                                class="text-[var(--mrl-borde)]"
                                >•</span
                            >
                            <span class="first-letter:uppercase">{{
                                dato
                            }}</span>
                        </template>
                        <span v-if="!meta.length"
                            >Vista previa del archivo</span
                        >
                    </DialogDescription>
                </div>

                <span
                    v-if="total > 1"
                    class="hidden shrink-0 rounded-full bg-crema px-2.5 py-1 text-xs font-medium text-[var(--mrl-petroleo)] tabular-nums md:inline"
                >
                    {{ indiceActual + 1 }} de {{ total }}
                </span>

                <div class="flex shrink-0 items-center gap-1.5">
                    <Button
                        as-child
                        size="sm"
                        variant="outline"
                        :disabled="!urlDescarga"
                    >
                        <a
                            :href="urlDescarga ?? '#'"
                            :download="actual?.nombre ?? ''"
                            aria-label="Descargar archivo"
                        >
                            <Download class="size-4" />
                            <span class="hidden sm:inline">Descargar</span>
                        </a>
                    </Button>
                    <Button
                        v-if="embebible && actual"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <a
                            :href="actual.previewUrl"
                            target="_blank"
                            rel="noopener"
                            aria-label="Abrir en pestaña nueva"
                        >
                            <ExternalLink class="size-4" />
                            <span class="hidden lg:inline"
                                >Abrir en pestaña nueva</span
                            >
                        </a>
                    </Button>
                    <DialogClose as-child>
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            aria-label="Cerrar vista previa"
                        >
                            <X class="size-4" />
                        </Button>
                    </DialogClose>
                </div>
            </header>

            <!-- Escenario -->
            <div
                ref="escenario"
                class="relative min-h-0 flex-1 bg-[var(--mrl-fondo)]"
                role="region"
                :aria-label="`Vista previa de ${actual?.nombre ?? 'archivo'}`"
                @wheel="alRueda"
            >
                <template v-if="actual">
                    <div :key="actual.clave" class="absolute inset-0">
                        <iframe
                            v-if="
                                (clase === 'pdf' || clase === 'texto') &&
                                estadoCarga !== 'error'
                            "
                            :src="actual.previewUrl"
                            :title="actual.nombre"
                            :class="
                                cn(
                                    'size-full border-0 transition-opacity duration-300',
                                    clase === 'texto' && 'bg-background',
                                    estadoCarga === 'cargando'
                                        ? 'opacity-0'
                                        : 'opacity-100',
                                )
                            "
                            @load="alCargarIframe"
                        />

                        <div
                            v-else-if="
                                clase === 'imagen' && estadoCarga !== 'error'
                            "
                            :class="
                                cn(
                                    'size-full',
                                    escala === null
                                        ? 'flex items-center justify-center p-4'
                                        : 'overflow-auto',
                                )
                            "
                        >
                            <div
                                :class="
                                    escala === null
                                        ? 'contents'
                                        : 'grid min-h-full min-w-full place-items-center p-4'
                                "
                            >
                                <img
                                    :src="actual.previewUrl"
                                    :alt="actual.nombre"
                                    :style="estiloImagen"
                                    draggable="false"
                                    :class="
                                        cn(
                                            'rounded-lg shadow-sm transition-opacity duration-300 select-none',
                                            escala === null
                                                ? 'max-h-full max-w-full cursor-zoom-in object-contain'
                                                : 'max-w-none cursor-zoom-out',
                                            estadoCarga === 'cargando'
                                                ? 'opacity-0'
                                                : 'opacity-100',
                                        )
                                    "
                                    @load="alCargarImagen"
                                    @error="marcarError()"
                                    @dblclick="alternarZoom"
                                />
                            </div>
                        </div>

                        <div
                            v-else-if="
                                clase === 'video' && estadoCarga !== 'error'
                            "
                            class="flex size-full items-center justify-center p-2 sm:p-4"
                        >
                            <video
                                :src="actual.previewUrl"
                                controls
                                playsinline
                                preload="metadata"
                                class="max-h-full max-w-full rounded-lg shadow-sm"
                                @loadedmetadata="estadoCarga = 'listo'"
                                @error="marcarError()"
                            >
                                Tu navegador no puede reproducir este video.
                            </video>
                        </div>

                        <div
                            v-else-if="
                                clase === 'audio' && estadoCarga !== 'error'
                            "
                            class="flex size-full flex-col items-center justify-center gap-4 p-6"
                        >
                            <span
                                class="flex size-20 items-center justify-center rounded-3xl bg-salvia text-[var(--mrl-petroleo)]"
                            >
                                <Music class="size-9" />
                            </span>
                            <audio
                                :src="actual.previewUrl"
                                controls
                                preload="metadata"
                                class="w-full max-w-md"
                                @loadedmetadata="estadoCarga = 'listo'"
                                @error="marcarError()"
                            />
                        </div>

                        <!-- Error o tipo sin vista previa (Word, Excel…) -->
                        <div
                            v-else
                            class="flex size-full items-center justify-center p-6"
                        >
                            <div
                                class="flex max-w-md flex-col items-center gap-3 rounded-2xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] px-6 py-8 text-center shadow-sm"
                            >
                                <span
                                    :class="
                                        cn(
                                            'flex size-16 items-center justify-center rounded-2xl',
                                            estadoCarga === 'error'
                                                ? 'bg-danger-soft text-danger'
                                                : TONOS[clase],
                                        )
                                    "
                                >
                                    <FileWarning
                                        v-if="estadoCarga === 'error'"
                                        class="size-8"
                                    />
                                    <component
                                        :is="iconoDe(actual)"
                                        v-else
                                        class="size-8"
                                    />
                                </span>
                                <template v-if="estadoCarga === 'error'">
                                    <p class="text-sm font-semibold">
                                        No pudimos mostrar el archivo
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        {{ mensajeError }}
                                    </p>
                                </template>
                                <template v-else>
                                    <p class="text-sm font-semibold">
                                        {{
                                            clase === 'office'
                                                ? `Los archivos ${formato} no se pueden ver aquí`
                                                : 'Este tipo de archivo no tiene vista previa'
                                        }}
                                    </p>
                                    <p class="text-sm text-muted-foreground">
                                        Descárgalo para abrirlo con tu programa
                                        habitual (Word, Excel…).
                                    </p>
                                </template>
                                <Button
                                    v-if="urlDescarga"
                                    as-child
                                    size="sm"
                                    class="mt-1"
                                >
                                    <a
                                        :href="urlDescarga"
                                        :download="actual.nombre"
                                    >
                                        <Download class="size-4" />
                                        Descargar
                                        <span
                                            v-if="actual.tamano"
                                            class="opacity-80"
                                            >({{
                                                formatearTamano(actual.tamano)
                                            }})</span
                                        >
                                    </a>
                                </Button>
                            </div>
                        </div>

                        <!-- Cargando -->
                        <div
                            v-if="estadoCarga === 'cargando' && embebible"
                            class="pointer-events-none absolute inset-0 flex items-center justify-center p-6"
                        >
                            <div
                                class="flex w-full max-w-sm flex-col items-center gap-4"
                            >
                                <div
                                    class="flex aspect-[3/4] w-40 flex-col gap-2 rounded-xl border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] p-4 shadow-sm"
                                    aria-hidden="true"
                                >
                                    <span
                                        class="h-2.5 w-3/4 animate-pulse rounded bg-crema"
                                    />
                                    <span
                                        class="h-2 w-full animate-pulse rounded bg-crema/70"
                                    />
                                    <span
                                        class="h-2 w-5/6 animate-pulse rounded bg-crema/70"
                                    />
                                    <span
                                        class="mt-2 flex-1 animate-pulse rounded-md bg-crema/50"
                                    />
                                </div>
                                <p
                                    class="flex items-center gap-2 text-sm text-muted-foreground"
                                    role="status"
                                >
                                    <Spinner class="size-4" />
                                    Cargando vista previa…
                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- Zoom de imagen -->
                    <div
                        v-if="clase === 'imagen' && estadoCarga === 'listo'"
                        class="absolute bottom-3 left-1/2 flex -translate-x-1/2 items-center gap-0.5 rounded-full border border-[var(--mrl-borde)] bg-[var(--mrl-superficie)]/95 p-1 shadow-md backdrop-blur"
                        role="toolbar"
                        aria-label="Zoom de la imagen"
                    >
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            class="rounded-full"
                            aria-label="Alejar"
                            @click="alejar"
                        >
                            <ZoomOut class="size-4" />
                        </Button>
                        <button
                            type="button"
                            class="min-w-14 rounded-full px-2 py-1 text-xs font-medium tabular-nums hover:bg-accent"
                            title="Ver a tamaño real"
                            @click="tamanoReal"
                        >
                            {{ porcentaje }}
                        </button>
                        <Button
                            size="icon-sm"
                            variant="ghost"
                            class="rounded-full"
                            aria-label="Acercar"
                            @click="acercar"
                        >
                            <ZoomIn class="size-4" />
                        </Button>
                        <span
                            class="mx-0.5 h-5 w-px bg-[var(--mrl-borde)]"
                            aria-hidden="true"
                        />
                        <Button
                            size="sm"
                            variant="ghost"
                            class="rounded-full"
                            :aria-pressed="escala === null"
                            @click="ajustar"
                        >
                            <Maximize class="size-4" />
                            <span class="hidden sm:inline">Ajustar</span>
                        </Button>
                    </div>

                    <!-- Anterior / siguiente -->
                    <template v-if="total > 1">
                        <Button
                            size="icon"
                            variant="outline"
                            class="absolute top-1/2 left-2 -translate-y-1/2 rounded-full bg-[var(--mrl-superficie)]/95 shadow-md backdrop-blur sm:left-4"
                            aria-label="Archivo anterior"
                            @click="anterior"
                        >
                            <ChevronLeft class="size-5" />
                        </Button>
                        <Button
                            size="icon"
                            variant="outline"
                            class="absolute top-1/2 right-2 -translate-y-1/2 rounded-full bg-[var(--mrl-superficie)]/95 shadow-md backdrop-blur sm:right-4"
                            aria-label="Archivo siguiente"
                            @click="siguiente"
                        >
                            <ChevronRight class="size-5" />
                        </Button>
                    </template>
                </template>

                <div
                    v-else
                    class="flex size-full flex-col items-center justify-center gap-2 p-8 text-center text-muted-foreground"
                >
                    <FileWarning class="size-10" />
                    <p class="text-sm">No hay archivo para mostrar.</p>
                </div>
            </div>

            <!-- Tira de archivos del grupo -->
            <nav
                v-if="total > 1"
                ref="tira"
                class="flex gap-2 overflow-x-auto border-t border-[var(--mrl-borde)] px-3 py-2 sm:px-5"
                aria-label="Archivos del grupo"
            >
                <button
                    v-for="(archivo, i) in lista"
                    :key="archivo.clave"
                    type="button"
                    :data-indice="i"
                    :aria-current="i === indiceActual ? 'true' : undefined"
                    :title="archivo.nombre"
                    :class="
                        cn(
                            'flex max-w-56 shrink-0 items-center gap-2 rounded-xl border px-2.5 py-1.5 text-left text-xs transition-colors',
                            i === indiceActual
                                ? 'border-oro bg-crema text-[var(--mrl-petroleo)]'
                                : 'border-[var(--mrl-borde)] bg-[var(--mrl-superficie)] text-muted-foreground hover:bg-accent hover:text-foreground',
                        )
                    "
                    @click="irA(i)"
                >
                    <component :is="iconoDe(archivo)" class="size-4 shrink-0" />
                    <span class="min-w-0">
                        <span
                            v-if="archivo.etiqueta"
                            class="block truncate text-[10px] font-semibold tracking-wide uppercase opacity-80"
                            >{{ archivo.etiqueta }}</span
                        >
                        <span class="block truncate">{{ archivo.nombre }}</span>
                    </span>
                </button>
            </nav>

            <p class="sr-only" aria-live="polite">
                <template v-if="actual && total > 1"
                    >Archivo {{ indiceActual + 1 }} de {{ total }}:
                    {{ actual.nombre }}</template
                >
            </p>
        </DialogContent>
    </Dialog>
</template>
