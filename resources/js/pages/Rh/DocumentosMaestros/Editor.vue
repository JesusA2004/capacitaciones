<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlignCenterHorizontal,
    AlignEndVertical,
    AlignHorizontalSpaceAround,
    AlignStartVertical,
    ArrowLeft,
    BringToFront,
    Check,
    CopyPlus,
    Eraser,
    Grid3x3,
    Highlighter,
    Loader2,
    MoreHorizontal,
    Redo2,
    Ruler,
    Save,
    ScanSearch,
    SendToBack,
    ShieldCheck,
    TextCursorInput,
    Undo2,
    Upload,
    UserRoundSearch,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { onKeyStroke, useDebounceFn, useEventListener } from '@vueuse/core';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
import CamposDatosFaltantes from '@/components/documentos/CamposDatosFaltantes.vue';
import ChecklistPreparacion from '@/components/documentos/editor/ChecklistPreparacion.vue';
import LienzoPdfEditor from '@/components/documentos/editor/LienzoPdfEditor.vue';
import MiniaturasPdf from '@/components/documentos/editor/MiniaturasPdf.vue';
import PanelPropiedades from '@/components/documentos/editor/PanelPropiedades.vue';
import PanelVariables from '@/components/documentos/editor/PanelVariables.vue';
import VistaDocxEditor from '@/components/documentos/editor/VistaDocxEditor.vue';
import PeopleFileDropzone from '@/components/people/PeopleFileDropzone.vue';
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
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuCheckboxItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useAlertas } from '@/composables/useAlertas';
import { solicitar, useDocumentosMaestros } from '@/composables/useDocumentosMaestros';
import { useHistorialEdicion } from '@/composables/useHistorialEdicion';
import { usePdfDocumento } from '@/composables/usePdfDocumento';
import { clonarSerializable } from '@/lib/clonar';
import { columnaDatoFaltante, datosFaltantesCompletos } from '@/lib/datosFaltantes';
import { campoNuevo, siguienteClave } from '@/lib/editorDocumentos';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    campos as camposRuta,
    copiarVersion as copiarVersionRuta,
    datosLargos as datosLargosRuta,
    detectar as detectarRuta,
    editor as editorRuta,
    index as indexMaestros,
    limpioPdf as limpioPdfRuta,
    marcas as marcasRuta,
    originalPdf as originalPdfRuta,
} from '@/routes/rh/documentos-maestros';
import type {
    CampoEditor,
    ColaboradorBusqueda,
    EditorMaestro,
    ResultadoDatosLargos,
    ResultadoPruebaMaster,
    VariableEditor,
} from '@/types';

/**
 * Editor visual NO-CODE de una versión de documento maestro: RH ve el
 * documento real, asigna/confirma qué dato va en cada espacio, prueba con
 * datos largos y con un colaborador real, y activa — sin editar PHP, config
 * ni escribir {{variables}}.
 */
const props = defineProps<{ editor: EditorMaestro; abrirPrueba?: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Documentos maestros', href: indexMaestros() },
            { title: 'Editor', href: '' },
        ],
    },
});

const api = useDocumentosMaestros();
const datos = ref<EditorMaestro>(clonarSerializable(props.editor));
const campos = computed({
    get: () => datos.value.campos,
    set: (v: CampoEditor[]) => (datos.value.campos = v),
});
const guardados = ref(JSON.stringify(props.editor.campos));
const sucio = computed(() => JSON.stringify(campos.value) !== guardados.value);
const editable = computed(() => datos.value.master.editable);
const esPdf = computed(() => datos.value.master.motor === 'pdf_overlay');
const historial = useHistorialEdicion(campos);

watch(
    () => props.editor,
    (nuevo) => {
        datos.value = clonarSerializable(nuevo);
        guardados.value = JSON.stringify(nuevo.campos);
        historial.reiniciar();
    },
);

// ───────── Selección ─────────
const seleccion = ref<string[]>([]);
const resaltado = ref<string | null>(null);
const pestana = ref<'espacio' | 'datos' | 'preparacion'>('preparacion');
const seleccionado = computed(() =>
    seleccion.value.length === 1 ? campos.value.find((c) => c.field_key === seleccion.value[0]) ?? null : null,
);

function seleccionar(fieldKey: string | null, aditivo: boolean) {
    if (fieldKey === null) {
        seleccion.value = [];

        return;
    }

    seleccion.value = aditivo
        ? seleccion.value.includes(fieldKey)
            ? seleccion.value.filter((k) => k !== fieldKey)
            : [...seleccion.value, fieldKey]
        : [fieldKey];
    pestana.value = 'espacio';

    const campo = campos.value.find((c) => c.field_key === fieldKey);

    if (campo?.page && esPdf.value) {
        paginaActual.value = campo.page;
    }
}

// ───────── PDF: páginas, zoom, guías ─────────
const pdf = usePdfDocumento(computed(() => (esPdf.value ? datos.value.url_fondo : null)));
const paginaActual = ref(1);
const zoom = ref(1);
const cuadricula = ref(false);
const regla = ref(true);
const pagina = computed(() => pdf.paginas.value.find((p) => p.numero === paginaActual.value) ?? null);

// ───────── Cambios ─────────
function cambiar(fieldKey: string, cambios: Partial<CampoEditor>, registrar = true) {
    if (registrar) {
        historial.registrar();
    }

    campos.value = campos.value.map((c) => {
        if (c.field_key !== fieldKey) {
            return c;
        }

        const nuevo = { ...c, ...cambios };

        // Elegir un dato = confirmarlo (verde); quitarlo = rojo.
        if ('source_key' in cambios || 'expression' in cambios) {
            nuevo.estado = nuevo.source_key || nuevo.expression ? 'confirmado' : 'sin_asignar';
        }

        if ('zona' in cambios && cambios.zona !== 'dato') {
            nuevo.estado = 'confirmado';
        }

        return nuevo;
    });
}

function confirmar(fieldKey: string) {
    cambiar(fieldKey, { estado: 'confirmado' });
}

function asignarVariable(variable: VariableEditor) {
    if (seleccionado.value) {
        cambiar(seleccionado.value.field_key, { source_key: variable.clave, expression: null, label: variable.etiqueta });
    }
}

function soltarEnPdf(clave: string, x: number, y: number) {
    const variable = datos.value.variables.flatMap((g) => g.variables).find((v) => v.clave === clave);
    historial.registrar();
    const nuevo = {
        ...campoNuevo(siguienteClave(campos.value), paginaActual.value, x, y),
        source_key: clave,
        label: variable?.etiqueta ?? clave,
        estado: 'confirmado' as const,
        type: variable?.tipo === 'casilla' ? ('casilla' as const) : ('texto' as const),
    };
    campos.value = [...campos.value, nuevo];
    seleccion.value = [nuevo.field_key];
    pestana.value = 'espacio';
}

function soltarEnDocx(fieldKey: string, clave: string) {
    const variable = datos.value.variables.flatMap((g) => g.variables).find((v) => v.clave === clave);
    cambiar(fieldKey, { source_key: clave, expression: null, label: variable?.etiqueta ?? clave });
    seleccion.value = [fieldKey];
}

function mover(cambios: { field_key: string; x: number; y: number }[]) {
    const porClave = new Map(cambios.map((c) => [c.field_key, c]));
    campos.value = campos.value.map((c) => {
        const m = porClave.get(c.field_key);

        return m ? { ...c, x: Math.round(m.x * 10) / 10, y: Math.round(m.y * 10) / 10 } : c;
    });
}

function redimensionar(fieldKey: string, width: number, height: number) {
    cambiar(fieldKey, { width: Math.round(width * 10) / 10, height: Math.round(height * 10) / 10 }, false);
}

function duplicar() {
    const origen = seleccionado.value;

    if (!origen || !esPdf.value) {
        return;
    }

    historial.registrar();
    const copia: CampoEditor = { ...clonarSerializable(origen), id: null, field_key: siguienteClave(campos.value), y: (origen.y ?? 0) + (origen.height ?? 5) + 1, bloqueado: false };
    campos.value = [...campos.value, copia];
    seleccion.value = [copia.field_key];
}

function eliminar() {
    if (!esPdf.value || seleccion.value.length === 0) {
        return;
    }

    historial.registrar();
    campos.value = campos.value.filter((c) => !seleccion.value.includes(c.field_key) || c.bloqueado);
    seleccion.value = [];
}

// Alinear / distribuir / orden (PDF, varios seleccionados).
const seleccionados = computed(() => campos.value.filter((c) => seleccion.value.includes(c.field_key) && !c.bloqueado));

function alinear(modo: 'izquierda' | 'centro' | 'derecha') {
    const lista = seleccionados.value;

    if (lista.length < 2) {
        return;
    }

    historial.registrar();
    const izquierda = Math.min(...lista.map((c) => c.x ?? 0));
    const derecha = Math.max(...lista.map((c) => (c.x ?? 0) + (c.width ?? 0)));
    const centro = (izquierda + derecha) / 2;

    mover(
        lista.map((c) => ({
            field_key: c.field_key,
            y: c.y ?? 0,
            x: modo === 'izquierda' ? izquierda : modo === 'derecha' ? derecha - (c.width ?? 0) : centro - (c.width ?? 0) / 2,
        })),
    );
}

function distribuir() {
    const lista = [...seleccionados.value].sort((a, b) => (a.y ?? 0) - (b.y ?? 0));

    if (lista.length < 3) {
        return;
    }

    historial.registrar();
    const inicio = lista[0].y ?? 0;
    const fin = lista[lista.length - 1].y ?? 0;
    const paso = (fin - inicio) / (lista.length - 1);
    mover(lista.map((c, i) => ({ field_key: c.field_key, x: c.x ?? 0, y: inicio + paso * i })));
}

function orden(alFrente: boolean) {
    if (seleccion.value.length === 0) {
        return;
    }

    historial.registrar();
    const elegidos = campos.value.filter((c) => seleccion.value.includes(c.field_key));
    const resto = campos.value.filter((c) => !seleccion.value.includes(c.field_key));
    campos.value = alFrente ? [...resto, ...elegidos] : [...elegidos, ...resto];
}

// ───────── Teclado ─────────
function enCampoDeTexto(e: KeyboardEvent): boolean {
    const t = e.target as HTMLElement | null;

    return !!t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable);
}

onKeyStroke((e) => {
    if (enCampoDeTexto(e) || !editable.value) {
        return;
    }

    const ctrl = e.ctrlKey || e.metaKey;

    if (ctrl && e.key.toLowerCase() === 'z') {
        e.preventDefault();

        if (e.shiftKey) {
            historial.rehacer();
        } else {
            historial.deshacer();
        }
    } else if (ctrl && e.key.toLowerCase() === 'y') {
        e.preventDefault();
        historial.rehacer();
    } else if (ctrl && e.key.toLowerCase() === 'd') {
        e.preventDefault();
        duplicar();
    } else if (ctrl && e.key.toLowerCase() === 's') {
        e.preventDefault();
        void guardar();
    } else if ((e.key === 'Delete' || e.key === 'Backspace') && seleccion.value.length) {
        e.preventDefault();
        eliminar();
    } else if (esPdf.value && seleccion.value.length && e.key.startsWith('Arrow')) {
        e.preventDefault();
        const paso = e.shiftKey ? 2 : 0.5;
        const dx = e.key === 'ArrowLeft' ? -paso : e.key === 'ArrowRight' ? paso : 0;
        const dy = e.key === 'ArrowUp' ? -paso : e.key === 'ArrowDown' ? paso : 0;
        historial.registrar();
        mover(seleccionados.value.map((c) => ({ field_key: c.field_key, x: (c.x ?? 0) + dx, y: (c.y ?? 0) + dy })));
    }
});

// ───────── Guardar (autoguardado) / probar / activar ─────────
const guardando = ref(false);
const errorGuardado = ref(false);
const arrastrando = ref(false);
let pendienteTrasGuardar = false;

/**
 * Guarda el mapa de campos. El autoguardado lo llama en silencio (el
 * indicador «Guardando… / Guardado» es el feedback); el botón Guardar y
 * Ctrl+S, con aviso. Si llegan cambios mientras guarda, vuelve a guardar al
 * terminar (nunca dos peticiones a la vez).
 */
async function guardar(silencioso = false): Promise<boolean> {
    if (guardando.value) {
        pendienteTrasGuardar = true;

        return false;
    }

    const enviado = JSON.stringify(campos.value);
    guardando.value = true;

    try {
        const r = await solicitar<{ message: string; data: EditorMaestro }>('PUT', camposRuta.url(datos.value.master.id), {
            campos: JSON.parse(enviado) as Record<string, unknown>[],
        });
        guardados.value = JSON.stringify(r.data.campos);

        // Si el usuario siguió editando durante la petición, se conserva lo
        // suyo (lo del servidor solo reemplaza si nada cambió).
        if (JSON.stringify(campos.value) === enviado) {
            datos.value = r.data;
        } else {
            datos.value = { ...r.data, campos: campos.value };
            pendienteTrasGuardar = true;
        }

        errorGuardado.value = false;

        if (!silencioso) {
            toast.success(r.message);
        }

        return true;
    } catch (e) {
        errorGuardado.value = true;
        toast.error(e instanceof Error ? e.message : 'No se pudo guardar.');

        return false;
    } finally {
        guardando.value = false;

        if (pendienteTrasGuardar) {
            pendienteTrasGuardar = false;
            void autoguardar();
        }
    }
}

const autoguardar = useDebounceFn(() => {
    if (editable.value && sucio.value && !arrastrando.value) {
        void guardar(true);
    }
}, 700);

watch(campos, () => void autoguardar(), { deep: true });

function alTerminarArrastre() {
    arrastrando.value = false;
    void autoguardar();
}

const estadoGuardado = computed(() => {
    if (guardando.value) {
        return 'Guardando…';
    }

    if (errorGuardado.value) {
        return 'Sin guardar';
    }

    return sucio.value ? 'Cambios sin guardar' : 'Guardado';
});

// No perder cambios al salir: navegación Inertia y cierre/recarga del navegador.
const quitarAvisoInertia = router.on('before', (evento) => {
    const metodo = evento.detail.visit.method;

    if (sucio.value && metodo === 'get' && !window.confirm('Hay cambios sin guardar en este formato. ¿Salir de todos modos?')) {
        return false;
    }
});
useEventListener(window, 'beforeunload', (e: BeforeUnloadEvent) => {
    if (sucio.value) {
        e.preventDefault();
    }
});
onBeforeUnmount(quitarAvisoInertia);

const largos = ref<ResultadoDatosLargos | null>(null);
const probandoLargos = ref(false);

async function probarDatosLargos() {
    if (sucio.value && !(await guardar())) {
        return;
    }

    probandoLargos.value = true;

    try {
        largos.value = (await solicitar<{ data: ResultadoDatosLargos }>('POST', datosLargosRuta.url(datos.value.master.id))).data;
        pestana.value = 'preparacion';
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo probar.');
    } finally {
        probandoLargos.value = false;
    }
}

function irA(fieldKey: string | null) {
    if (!fieldKey) {
        return;
    }

    seleccionar(fieldKey, false);
    resaltado.value = fieldKey;
    window.setTimeout(() => (resaltado.value = null), 2500);
}

const pruebaAbierta = ref(props.abrirPrueba === true);
const colaborador = ref<ColaboradorBusqueda | null>(null);
const probando = ref(false);
const prueba = ref<ResultadoPruebaMaster | null>(null);
// «Completar datos del contrato»: se capturan ahí mismo, se guardan en la
// ficha y la vista previa se regenera sola.
const completando = ref(false);
const valoresFaltantes = ref<Record<string, string>>({});
const faltantesEditables = computed(() => prueba.value?.datos_faltantes.filter((f) => f.editable) ?? []);
const puedeGuardarFaltantes = computed(
    () => faltantesEditables.value.length > 0 && datosFaltantesCompletos(prueba.value?.datos_faltantes ?? [], valoresFaltantes.value),
);

async function probarColaborador(completar: Record<string, string> = {}) {
    if (!colaborador.value) {
        return;
    }

    if (sucio.value && !(await guardar())) {
        return;
    }

    probando.value = true;

    try {
        prueba.value = await api.probar(datos.value.master.id, colaborador.value.id, completar);
        completando.value = false;
        valoresFaltantes.value = Object.fromEntries(prueba.value.datos_faltantes.map((f) => [columnaDatoFaltante(f), '']));

        if (Object.keys(completar).length) {
            toast.success(prueba.value.faltantes.length ? 'Datos guardados. Aún faltan algunos.' : 'Datos guardados: el documento ya está completo.');
        }
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo generar la prueba.');
    } finally {
        probando.value = false;
    }
}

function guardarFaltantes() {
    if (!puedeGuardarFaltantes.value) {
        return;
    }

    void probarColaborador(Object.fromEntries(Object.entries(valoresFaltantes.value).filter(([, v]) => v.trim() !== '')));
}

// ───────── Marcatextos del original (limpieza con vista previa) ─────────
const marcasAbierto = ref(false);
const vistaMarcas = ref<'original' | 'limpio'>('limpio');
const decidiendoMarcas = ref(false);
const marcas = computed(() => datos.value.master.marcas);

async function decidirMarcas(decision: 'limpiar' | 'conservar') {
    decidiendoMarcas.value = true;

    try {
        const r = await solicitar<{ message: string; data: EditorMaestro }>('POST', marcasRuta.url(datos.value.master.id), { decision });
        datos.value = r.data;
        guardados.value = JSON.stringify(r.data.campos);
        marcasAbierto.value = false;
        toast.success(r.message);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo aplicar la decisión.');
    } finally {
        decidiendoMarcas.value = false;
    }
}

const activando = ref(false);
const { confirmarAccion } = useAlertas();

async function activar() {
    if (
        !(await confirmarAccion(
            'Activar versión oficial',
            `Desde este momento, los documentos de «${datos.value.master.nombre}» se generarán con la v${datos.value.master.version}.`,
            'Activar',
        ))
    ) {
        return;
    }

    if (sucio.value && !(await guardar())) {
        return;
    }

    activando.value = true;

    try {
        const r = await api.activar(datos.value.master.id);
        toast.success(r.message);
        router.reload({ only: ['editor'] });
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo activar.');
    } finally {
        activando.value = false;
    }
}

// ───────── Detectar / versiones ─────────
const detectando = ref(false);

async function detectarEspacios() {
    detectando.value = true;

    try {
        const r = await solicitar<{ message: string; data: EditorMaestro }>('POST', detectarRuta.url(datos.value.master.id));
        datos.value = r.data;
        guardados.value = JSON.stringify(r.data.campos);
        toast.success(r.message);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo detectar.');
    } finally {
        detectando.value = false;
    }
}

const siguienteVersion = computed(() => Math.max(datos.value.master.version, ...datos.value.versiones.map((v) => v.version)) + 1);
const copiando = ref(false);

async function editarEnVersionNueva() {
    copiando.value = true;

    try {
        const r = await solicitar<{ message: string; data: { id: number } }>('POST', copiarVersionRuta.url(datos.value.master.id));
        toast.success(r.message);
        router.visit(editorRuta.url(r.data.id));
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo crear la versión.');
    } finally {
        copiando.value = false;
    }
}

const subirAbierto = ref(false);
const archivos = ref<File[]>([]);
const subiendo = ref(false);

async function subirVersion() {
    const archivo = archivos.value[0];

    if (!archivo) {
        return;
    }

    subiendo.value = true;

    try {
        const r = await api.cargarVersion(datos.value.master.familia, archivo);
        toast.success(r.message);
        router.visit(editorRuta.url(r.data.id));
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo cargar la versión.');
    } finally {
        subiendo.value = false;
    }
}

const numeroDe = (fieldKey: string) => campos.value.findIndex((c) => c.field_key === fieldKey) + 1;

/**
 * ¿Este espacio requiere atención? (rojo/amarillo, o «en blanco a
 * propósito» sin su razón). Firmas y vacíos explicados están resueltos.
 */
function requiereAtencion(c: CampoEditor): boolean {
    if (c.zona !== 'dato') {
        return false;
    }

    if (c.estado !== 'confirmado' || (!c.source_key && !c.expression)) {
        return true;
    }

    return !c.required && c.type !== 'casilla' && !(c.motivo_blanco ?? '').trim();
}

// Espacios por revisar en orden del documento + los que el detector halló
// fuera del mapa (líneas sin espacio, datos de ejemplo).
const porRevisar = computed(() => campos.value.filter(requiereAtencion));
const problemasDocumento = computed(() => datos.value.preparacion.cobertura.problemas.filter((p) => p.field_key === null));
const resumen = computed(() => {
    // Firmas que el detector reconoció en el Word sin espacio propio en el mapa.
    const firmasEnMapa = campos.value.filter((c) => ['firma', 'huella', 'testigo'].includes(c.zona)).length;
    const firmasDetector = Math.max(0, datos.value.preparacion.cobertura.firmas - firmasEnMapa);
    const atencion = porRevisar.value.length + problemasDocumento.value.length;
    const zonas = campos.value.length + problemasDocumento.value.length + firmasDetector;

    return { zonas, listos: zonas - atencion, atencion };
});

/** Lleva al siguiente espacio por revisar (después del seleccionado). */
function siguientePorRevisar() {
    const lista = porRevisar.value;

    if (!lista.length) {
        pestana.value = 'preparacion';

        return;
    }

    const actual = seleccion.value[0] ? campos.value.findIndex((c) => c.field_key === seleccion.value[0]) : -1;
    const siguiente = lista.find((c) => campos.value.indexOf(c) > actual) ?? lista[0];
    irA(siguiente.field_key);
}
</script>

<template>
    <Head :title="`Editor · ${datos.master.nombre}`" />

    <TooltipProvider :delay-duration="300">
        <div class="pagina-ancha flex h-[calc(100dvh-5rem)] min-h-[36rem] flex-col gap-3">
            <!-- Barra superior: lo esencial a la vista, lo demás en «…» -->
            <header class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card px-3 py-2 shadow-xs">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="indexMaestros()" aria-label="Volver a documentos maestros"><ArrowLeft class="size-4" /></Link>
                </Button>
                <div class="mr-auto min-w-0">
                    <p class="truncate font-semibold">{{ datos.master.nombre }}</p>
                    <p class="flex items-center gap-1.5 truncate text-xs text-muted-foreground">
                        <span>{{ datos.master.aplica_a }} · v{{ datos.master.version }}</span>
                        <Badge v-if="datos.master.activo" variant="secondary" class="bg-success-soft text-success">Activa</Badge>
                        <span v-if="editable" class="inline-flex items-center gap-1" aria-live="polite" :class="errorGuardado ? 'text-danger' : ''">
                            ·
                            <Loader2 v-if="guardando" class="size-3 animate-spin" />
                            <Check v-else-if="!sucio && !errorGuardado" class="size-3 text-success" />
                            {{ estadoGuardado }}
                        </span>
                    </p>
                </div>

                <div class="hidden items-center gap-1.5 text-xs md:flex">
                    <span class="rounded-full bg-success-soft px-2 py-0.5 text-success tabular-nums">{{ resumen.listos }} / {{ resumen.zonas }} espacios listos</span>
                    <button
                        v-if="resumen.atencion"
                        type="button"
                        class="rounded-full bg-warning-soft px-2 py-0.5 text-warning hover:ring-1 hover:ring-warning"
                        @click="siguientePorRevisar"
                    >
                        ⚠ {{ resumen.atencion }} por revisar · Revisar
                    </button>
                </div>

                <div v-if="editable" class="flex items-center gap-0.5 border-l pl-2">
                    <Tooltip>
                        <TooltipTrigger as-child><Button size="icon" variant="ghost" :disabled="!historial.puedeDeshacer.value" aria-label="Deshacer" @click="historial.deshacer()"><Undo2 class="size-4" /></Button></TooltipTrigger>
                        <TooltipContent>Deshacer (Ctrl+Z)</TooltipContent>
                    </Tooltip>
                    <Tooltip>
                        <TooltipTrigger as-child><Button size="icon" variant="ghost" :disabled="!historial.puedeRehacer.value" aria-label="Rehacer" @click="historial.rehacer()"><Redo2 class="size-4" /></Button></TooltipTrigger>
                        <TooltipContent>Rehacer (Ctrl+Y)</TooltipContent>
                    </Tooltip>
                </div>

                <div v-if="esPdf" class="flex items-center gap-0.5 border-l pl-2">
                    <Button size="icon" variant="ghost" aria-label="Alejar" @click="zoom = Math.max(0.5, zoom - 0.25)"><ZoomOut class="size-4" /></Button>
                    <span class="w-10 text-center text-xs tabular-nums">{{ Math.round(zoom * 100) }}%</span>
                    <Button size="icon" variant="ghost" aria-label="Acercar" @click="zoom = Math.min(3, zoom + 0.25)"><ZoomIn class="size-4" /></Button>
                </div>

                <div v-if="esPdf && seleccion.length > 1" class="flex items-center gap-0.5 border-l pl-2">
                    <Button size="icon" variant="ghost" aria-label="Alinear a la izquierda" @click="alinear('izquierda')"><AlignStartVertical class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Centrar" @click="alinear('centro')"><AlignCenterHorizontal class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Alinear a la derecha" @click="alinear('derecha')"><AlignEndVertical class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Distribuir" @click="distribuir"><AlignHorizontalSpaceAround class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Traer al frente" @click="orden(true)"><BringToFront class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Enviar atrás" @click="orden(false)"><SendToBack class="size-4" /></Button>
                </div>

                <div class="flex items-center gap-1.5 border-l pl-2">
                    <Button size="sm" variant="outline" @click="pruebaAbierta = true"><UserRoundSearch class="size-4" /> Probar</Button>
                    <Button v-if="editable" size="sm" :disabled="!sucio || guardando" @click="guardar()">
                        <Loader2 v-if="guardando" class="size-4 animate-spin" /><Save v-else class="size-4" /> Guardar
                    </Button>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button size="icon" variant="ghost" aria-label="Más opciones del editor"><MoreHorizontal class="size-4" /></Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-60">
                            <template v-if="esPdf">
                                <DropdownMenuCheckboxItem v-model="cuadricula"><Grid3x3 class="size-4" /> Cuadrícula</DropdownMenuCheckboxItem>
                                <DropdownMenuCheckboxItem v-model="regla"><Ruler class="size-4" /> Regla</DropdownMenuCheckboxItem>
                                <DropdownMenuSeparator />
                            </template>
                            <DropdownMenuItem :disabled="probandoLargos" @click="probarDatosLargos"><TextCursorInput class="size-4" /> Probar datos largos</DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editable || detectando" @click="detectarEspacios"><ScanSearch class="size-4" /> Volver a detectar espacios</DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuLabel class="text-xs text-muted-foreground">Versiones</DropdownMenuLabel>
                            <DropdownMenuItem v-for="v in datos.versiones" :key="v.id" as="a" :href="editorRuta.url(v.id)">
                                <Check v-if="v.id === datos.master.id" class="size-4" /><span v-else class="size-4" />
                                v{{ v.version }} · {{ v.estado_etiqueta }}
                            </DropdownMenuItem>
                            <DropdownMenuItem @click="subirAbierto = true"><Upload class="size-4" /> Subir nueva versión</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <div v-if="!editable && datos.master.motivo_no_editable" class="flex flex-wrap items-center gap-3 rounded-xl bg-info-soft px-4 py-2 text-sm">
                <span class="mr-auto">{{ datos.master.activo ? 'Esta versión está en uso.' : datos.master.motivo_no_editable }}</span>
                <Button size="sm" :disabled="copiando" @click="editarEnVersionNueva">
                    <Loader2 v-if="copiando" class="size-4 animate-spin" /><CopyPlus v-else class="size-4" /> Crear v{{ siguienteVersion }} para editar
                </Button>
            </div>
            <p v-if="datos.master.aviso_deteccion && editable" class="rounded-xl bg-warning-soft px-4 py-2 text-sm text-warning">
                {{ datos.master.aviso_deteccion }}
            </p>

            <div class="grid min-h-0 flex-1 grid-cols-1 gap-3 lg:grid-cols-[9rem_minmax(0,1fr)_20rem]">
                <!-- Izquierda: páginas / espacios -->
                <aside class="hidden min-h-0 overflow-y-auto rounded-2xl border bg-card p-2 lg:block">
                    <MiniaturasPdf v-if="esPdf" :paginas="pdf.paginas.value" :actual="paginaActual" :campos="campos" :dibujar="pdf.dibujar" @elegir="(n) => (paginaActual = n)" />
                    <ol v-else class="flex flex-col gap-1">
                        <li v-for="(c, i) in campos" :key="c.field_key">
                            <button
                                type="button"
                                :class="cn('flex w-full items-center gap-1.5 rounded-md px-1.5 py-1 text-left text-xs', seleccion.includes(c.field_key) ? 'bg-primary/10' : 'hover:bg-muted')"
                                @click="seleccionar(c.field_key, false)"
                            >
                                <span
                                    :class="
                                        cn(
                                            'flex size-5 shrink-0 items-center justify-center rounded-full text-[10px] font-semibold',
                                            c.zona !== 'dato' ? 'bg-muted text-muted-foreground' : c.estado === 'confirmado' ? 'bg-success-soft text-success' : c.estado === 'sugerido' ? 'bg-warning-soft text-warning' : 'bg-danger-soft text-danger',
                                        )
                                    "
                                    >{{ i + 1 }}</span
                                >
                                <span class="truncate">{{ c.label ?? 'Sin dato' }}</span>
                            </button>
                        </li>
                    </ol>
                </aside>

                <!-- Centro: documento -->
                <main class="min-h-0 overflow-auto rounded-2xl border bg-crema/50">
                    <div v-if="esPdf && pdf.cargando.value" class="flex h-full items-center justify-center gap-2 text-sm text-muted-foreground">
                        <Loader2 class="size-4 animate-spin" /> Cargando documento…
                    </div>
                    <p v-else-if="esPdf && pdf.fallo.value" class="p-10 text-center text-sm text-danger">No se pudo mostrar el documento original.</p>
                    <LienzoPdfEditor
                        v-else-if="esPdf"
                        :pagina="pagina"
                        :campos="campos"
                        :seleccion="seleccion"
                        :zoom="zoom"
                        :cuadricula="cuadricula"
                        :regla="regla"
                        :editable="editable"
                        :dibujar="pdf.dibujar"
                        :resaltado="resaltado"
                        @seleccionar="seleccionar"
                        @antes-de-cambiar="historial.registrar(); arrastrando = true"
                        @fin-de-cambio="alTerminarArrastre"
                        @mover="mover"
                        @redimensionar="redimensionar"
                        @soltar-variable="soltarEnPdf"
                    />
                    <VistaDocxEditor
                        v-else
                        :parrafos="datos.parrafos"
                        :campos="campos"
                        :seleccion="seleccion"
                        :editable="editable"
                        :resaltado="resaltado"
                        @seleccionar="seleccionar"
                        @soltar-variable="soltarEnDocx"
                    />
                </main>

                <!-- Derecha: espacio / datos / preparación -->
                <aside class="flex min-h-0 flex-col rounded-2xl border bg-card p-3">
                    <Tabs v-model="pestana" class="flex min-h-0 flex-1 flex-col">
                        <TabsList class="grid w-full grid-cols-3">
                            <TabsTrigger value="espacio">Espacio</TabsTrigger>
                            <TabsTrigger value="datos">Datos</TabsTrigger>
                            <TabsTrigger value="preparacion">Estado</TabsTrigger>
                        </TabsList>
                        <TabsContent value="espacio" class="min-h-0 flex-1 overflow-y-auto pt-3">
                            <PanelPropiedades
                                v-if="seleccionado"
                                :campo="seleccionado"
                                :numero="numeroDe(seleccionado.field_key)"
                                :motor="datos.master.motor"
                                :grupos="datos.variables"
                                :editable="editable"
                                :total="campos.length"
                                :pendientes="porRevisar.filter((c) => c.field_key !== seleccionado!.field_key).length"
                                @siguiente="siguientePorRevisar"
                                @cambiar="(c) => cambiar(seleccionado!.field_key, c)"
                                @confirmar="confirmar(seleccionado.field_key)"
                                @duplicar="duplicar"
                                @eliminar="eliminar"
                            />
                            <p v-else-if="seleccion.length > 1" class="text-sm text-muted-foreground">{{ seleccion.length }} espacios seleccionados: usa alinear y distribuir arriba.</p>
                            <p v-else class="text-sm text-muted-foreground">Selecciona un espacio del documento{{ esPdf ? ' o arrastra un dato encima' : '' }}.</p>
                        </TabsContent>
                        <TabsContent value="datos" class="flex min-h-0 flex-1 flex-col pt-3">
                            <PanelVariables :grupos="datos.variables" :habilitado="editable" :seleccionado="seleccionado !== null" @elegir="asignarVariable" />
                        </TabsContent>
                        <TabsContent value="preparacion" class="min-h-0 flex-1 overflow-y-auto pt-3">
                            <!-- Preparar formato: cuántos espacios tiene el documento y cuántos faltan -->
                            <div class="mb-4 rounded-xl border bg-crema/40 p-3 text-sm">
                                <p class="font-medium">Detectamos {{ resumen.zonas }} espacio{{ resumen.zonas === 1 ? '' : 's' }}</p>
                                <p class="mt-1 text-success">✓ {{ resumen.listos }} listo{{ resumen.listos === 1 ? '' : 's' }}</p>
                                <p v-if="resumen.atencion" class="text-warning">⚠ {{ resumen.atencion }} por revisar</p>
                                <p class="mt-1 text-xs text-muted-foreground">
                                    {{ datos.preparacion.cobertura.dinamicas }} con dato · {{ datos.preparacion.cobertura.firmas }} de firma
                                    <template v-if="datos.preparacion.cobertura.manuales"> · {{ datos.preparacion.cobertura.manuales }} se capturan al generar</template>
                                    <template v-if="datos.preparacion.cobertura.intencionales"> · {{ datos.preparacion.cobertura.intencionales }} en blanco a propósito</template>
                                </p>
                                <Button v-if="porRevisar.length" size="sm" class="mt-2 w-full" @click="siguientePorRevisar">Continuar</Button>
                                <ul v-if="problemasDocumento.length" class="mt-2 flex flex-col gap-1">
                                    <li v-for="(p, i) in problemasDocumento" :key="i" class="rounded-md bg-warning-soft px-2 py-1 text-xs text-warning">
                                        <span class="font-medium">{{ p.etiqueta }}</span> — {{ p.mensaje }}
                                        <span v-if="p.contexto" class="block opacity-80">«{{ p.contexto }}»</span>
                                    </li>
                                </ul>
                            </div>

                            <!-- Marcatextos del original -->
                            <div v-if="marcas.detectadas" class="mb-4 rounded-xl border p-3 text-sm">
                                <p class="flex items-center gap-1.5 font-medium"><Highlighter class="size-4" /> Resaltados del original</p>
                                <p v-if="marcas.decision === 'limpiar'" class="mt-1 text-success">✓ {{ marcas.detectadas }} resaltado{{ marcas.detectadas === 1 ? '' : 's' }} retirado{{ marcas.detectadas === 1 ? '' : 's' }} del documento final</p>
                                <p v-else-if="marcas.decision === 'conservar'" class="mt-1 text-muted-foreground">Se conservan como parte del diseño.</p>
                                <p v-else class="mt-1 text-warning">El documento trae {{ marcas.detectadas }} marca{{ marcas.detectadas === 1 ? '' : 's' }} de marcatextos. Revísalas antes de activar.</p>
                                <Button size="sm" variant="outline" class="mt-2 w-full" @click="marcasAbierto = true"><Eraser class="size-4" /> Limpiar marcas editoriales</Button>
                            </div>

                            <ChecklistPreparacion :preparacion="datos.preparacion" />
                            <Button
                                v-if="!datos.master.activo && datos.preparacion.puede_activar"
                                class="mt-3 w-full bg-success text-success-foreground hover:bg-success/90"
                                :disabled="activando"
                                @click="activar"
                            >
                                <Loader2 v-if="activando" class="size-4 animate-spin" /><ShieldCheck v-else class="size-4" /> Activar esta versión
                            </Button>
                            <div v-if="largos" class="mt-4 rounded-lg border p-3 text-sm">
                                <p :class="largos.ok ? 'font-medium text-success' : 'font-medium text-warning'">
                                    {{ largos.ok ? '✓ Todo cabe' : `⚠ ${largos.campos.length} campo(s) necesitan atención` }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ largos.mensaje }}</p>
                                <ul v-if="largos.campos.length" class="mt-2 flex flex-col gap-1">
                                    <li v-for="c in largos.campos" :key="`${c.field_key}-${c.etiqueta}`">
                                        <button type="button" class="w-full rounded-md bg-warning-soft px-2 py-1 text-left text-xs text-warning hover:ring-1 hover:ring-warning" @click="irA(c.field_key)">
                                            <span class="font-medium">{{ c.etiqueta }}</span> — {{ c.razon }}
                                        </button>
                                    </li>
                                </ul>
                                <a v-if="largos.url_pdf" :href="largos.url_pdf" target="_blank" rel="noopener" class="mt-2 inline-block text-xs text-primary underline">Ver PDF de prueba</a>
                            </div>
                        </TabsContent>
                    </Tabs>
                </aside>
            </div>
        </div>
    </TooltipProvider>

    <!-- Nueva versión (archivo nuevo de Jurídico) -->
    <Dialog :open="subirAbierto" @update:open="(v: boolean) => !subiendo && (subirAbierto = v)">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Subir nueva versión</DialogTitle>
                <DialogDescription>Se copia el mapa actual y se marca en amarillo lo que ya no calce. No se activa sola.</DialogDescription>
            </DialogHeader>
            <PeopleFileDropzone
                v-model="archivos"
                :accept="esPdf ? '.pdf' : '.docx'"
                :max-size-mb="20"
                :loading="subiendo"
                label="Arrastra aquí el documento"
                :hint="esPdf ? 'PDF · Máx. 20 MB' : 'Word (.docx) · Máx. 20 MB'"
                @error="(m: string) => toast.error(m)"
            />
            <DialogFooter>
                <Button variant="outline" :disabled="subiendo" @click="subirAbierto = false">Cancelar</Button>
                <Button :disabled="subiendo || !archivos.length" @click="subirVersion">
                    <Loader2 v-if="subiendo" class="size-4 animate-spin" /> Subir
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Probar con un colaborador real -->
    <Dialog v-model:open="pruebaAbierta">
        <DialogContent :class="prueba ? 'max-h-[96dvh] overflow-y-auto sm:max-w-[min(96vw,90rem)]' : 'sm:max-w-lg'">
            <DialogHeader>
                <DialogTitle>Probar con colaborador</DialogTitle>
                <DialogDescription>Vista previa con sus datos reales. No se guarda en su expediente.</DialogDescription>
            </DialogHeader>
            <div class="flex flex-wrap items-end gap-2">
                <div class="min-w-64 flex-1"><BuscadorColaborador v-model="colaborador" /></div>
                <Button :disabled="!colaborador || probando" @click="probarColaborador()">
                    <Loader2 v-if="probando" class="size-4 animate-spin" /> {{ prueba ? 'Actualizar vista previa' : 'Generar vista previa' }}
                </Button>
            </div>
            <div v-if="prueba" class="grid gap-3 text-sm lg:grid-cols-[minmax(0,1fr)_20rem]">
                <!-- Centro: el PDF (el visor del navegador trae las páginas a la izquierda) -->
                <iframe :key="prueba.url_resultado" :src="prueba.url_resultado" title="Vista previa del documento" class="h-[75dvh] w-full rounded-lg border bg-white" />

                <!-- Derecha: el problema actual -->
                <aside class="flex flex-col gap-3">
                    <div v-if="prueba.faltantes.length" class="rounded-lg bg-warning-soft px-3 py-2 text-warning">
                        <p class="font-semibold">Faltan datos para generar este documento</p>
                        <p class="mt-0.5 text-xs">
                            Usa {{ prueba.datos_usados }} dato{{ prueba.datos_usados === 1 ? '' : 's' }}; a {{ prueba.colaborador.nombre }} le
                            falta{{ prueba.faltantes.length === 1 ? '' : 'n' }} {{ prueba.faltantes.length }}:
                        </p>
                        <ul class="mt-1 list-disc pl-5 text-sm">
                            <li v-for="f in prueba.faltantes" :key="f">{{ f }}</li>
                        </ul>
                        <Button v-if="!completando && faltantesEditables.length" size="sm" class="mt-2 w-full" @click="completando = true">Completar datos</Button>
                    </div>
                    <p v-else class="rounded-lg bg-success-soft px-3 py-2 text-success">✓ Tiene todos los datos que pide el documento.</p>

                    <div v-if="completando" class="rounded-lg border p-3">
                        <p class="mb-3 font-medium">Completar datos del documento</p>
                        <CamposDatosFaltantes v-model="valoresFaltantes" :faltantes="prueba.datos_faltantes" />
                        <div class="mt-3 flex gap-2">
                            <Button variant="outline" size="sm" :disabled="probando" @click="completando = false">Cancelar</Button>
                            <Button size="sm" class="flex-1" :disabled="probando || !puedeGuardarFaltantes" @click="guardarFaltantes">
                                <Loader2 v-if="probando" class="size-4 animate-spin" /> Guardar y actualizar vista previa
                            </Button>
                        </div>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        {{ prueba.paginas.generado }} página(s) · {{ prueba.campos.llenos }} de {{ prueba.campos.total }} datos ·
                        <span :class="prueba.desbordes.length ? 'text-warning' : 'text-success'">{{ prueba.desbordes.length ? `${prueba.desbordes.length} dato(s) no caben` : 'todo cabe' }}</span>
                    </p>
                    <ul v-if="prueba.desbordes.length" class="flex flex-col gap-1 text-xs">
                        <li v-for="d in prueba.desbordes" :key="d.campo" class="rounded-md bg-warning-soft px-2 py-1 text-warning">
                            <span class="font-medium">{{ d.etiqueta ?? d.campo }}</span> — {{ d.razon }}
                        </li>
                    </ul>
                </aside>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="pruebaAbierta = false">Cerrar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Limpiar marcas editoriales: antes / después, luego aplicar -->
    <Dialog v-model:open="marcasAbierto">
        <DialogContent class="max-h-[96dvh] overflow-y-auto sm:max-w-[min(96vw,80rem)]">
            <DialogHeader>
                <DialogTitle>Limpiar marcas editoriales</DialogTitle>
                <DialogDescription>
                    Detectamos {{ marcas.detectadas }} resaltado{{ marcas.detectadas === 1 ? '' : 's' }} de marcatextos en el original. El archivo
                    original no se modifica: solo se quitan del documento que genera PEOPLE.
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-3 lg:grid-cols-[minmax(0,1fr)_18rem]">
                <div class="flex flex-col gap-2">
                    <div class="flex flex-wrap items-center gap-3">
                        <Tabs v-model="vistaMarcas">
                            <TabsList>
                                <TabsTrigger value="original">Original</TabsTrigger>
                                <TabsTrigger value="limpio">Limpio</TabsTrigger>
                            </TabsList>
                        </Tabs>
                        <p class="text-xs text-muted-foreground">La vista «Limpio» se genera con el conversor del servidor: la primera vez tarda unos segundos.</p>
                    </div>
                    <iframe
                        :key="vistaMarcas"
                        :src="vistaMarcas === 'original' ? originalPdfRuta.url(datos.master.id) : limpioPdfRuta.url(datos.master.id)"
                        :title="vistaMarcas === 'original' ? 'Original con resaltados' : 'Documento limpio'"
                        class="h-[70dvh] w-full rounded-lg border bg-white"
                    />
                </div>
                <aside class="flex flex-col gap-2 text-sm">
                    <p class="font-medium">Elementos detectados</p>
                    <ul class="flex max-h-[55dvh] flex-col gap-1 overflow-y-auto">
                        <li v-for="(m, i) in marcas.lista" :key="i" class="rounded-md border px-2 py-1 text-xs">
                            <span class="flex items-center gap-1.5">
                                <span class="inline-block size-3 rounded-sm border" :style="{ background: m.color.startsWith('#') ? m.color : undefined }" :class="!m.color.startsWith('#') && 'bg-success-soft'" />
                                {{ m.tipo === 'resaltado' ? 'Resaltado editorial' : 'Sombreado de texto' }}
                            </span>
                            <span class="mt-0.5 line-clamp-2 block text-muted-foreground">«{{ m.texto || '(sin texto)' }}»</span>
                        </li>
                    </ul>
                </aside>
            </div>
            <DialogFooter class="gap-2">
                <Button variant="outline" :disabled="decidiendoMarcas || !editable" @click="decidirMarcas('conservar')">Conservar (son parte del diseño)</Button>
                <Button :disabled="decidiendoMarcas || !editable" @click="decidirMarcas('limpiar')">
                    <Loader2 v-if="decidiendoMarcas" class="size-4 animate-spin" /><Eraser v-else class="size-4" /> Aplicar limpieza
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
