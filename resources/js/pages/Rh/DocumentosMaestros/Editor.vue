<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlignCenterHorizontal,
    AlignEndVertical,
    AlignHorizontalSpaceAround,
    AlignStartVertical,
    ArrowLeft,
    BringToFront,
    CopyPlus,
    Grid3x3,
    History,
    Loader2,
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
import { onKeyStroke } from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import BuscadorColaborador from '@/components/documentos/BuscadorColaborador.vue';
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
import { solicitar, useDocumentosMaestros } from '@/composables/useDocumentosMaestros';
import { useHistorialEdicion } from '@/composables/useHistorialEdicion';
import { usePdfDocumento } from '@/composables/usePdfDocumento';
import { clonarSerializable } from '@/lib/clonar';
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

// ───────── Guardar / probar / activar ─────────
const guardando = ref(false);

async function guardar(): Promise<boolean> {
    guardando.value = true;

    try {
        const r = await solicitar<{ message: string; data: EditorMaestro }>('PUT', camposRuta.url(datos.value.master.id), {
            campos: campos.value as unknown as Record<string, unknown>[],
        });
        datos.value = r.data;
        guardados.value = JSON.stringify(r.data.campos);
        toast.success(r.message);

        return true;
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo guardar.');

        return false;
    } finally {
        guardando.value = false;
    }
}

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

async function probarColaborador() {
    if (!colaborador.value) {
        return;
    }

    if (sucio.value && !(await guardar())) {
        return;
    }

    probando.value = true;

    try {
        prueba.value = await api.probar(datos.value.master.id, colaborador.value.id);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : 'No se pudo generar la prueba.');
    } finally {
        probando.value = false;
    }
}

const activando = ref(false);

async function activar() {
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

const resumen = computed(() => {
    const datosZona = campos.value.filter((c) => c.zona === 'dato');

    return {
        confirmados: datosZona.filter((c) => c.estado === 'confirmado' && (c.source_key || c.expression)).length,
        porConfirmar: datosZona.filter((c) => c.estado === 'sugerido').length,
        sinDato: datosZona.filter((c) => c.estado === 'sin_asignar' || (!c.source_key && !c.expression)).length,
        firmas: campos.value.length - datosZona.length,
    };
});
</script>

<template>
    <Head :title="`Editor · ${datos.master.nombre}`" />

    <TooltipProvider :delay-duration="300">
        <div class="pagina-ancha flex h-[calc(100dvh-5rem)] min-h-[36rem] flex-col gap-3">
            <!-- Barra superior -->
            <header class="flex flex-wrap items-center gap-2 rounded-2xl border bg-card px-3 py-2 shadow-xs">
                <Button variant="ghost" size="icon" as-child>
                    <Link :href="indexMaestros()" aria-label="Volver a documentos maestros"><ArrowLeft class="size-4" /></Link>
                </Button>
                <div class="mr-auto min-w-0">
                    <p class="truncate font-semibold">{{ datos.master.nombre }}</p>
                    <p class="truncate text-xs text-muted-foreground">
                        {{ datos.master.aplica_a }} · v{{ datos.master.version }}
                        <Badge v-if="datos.master.activo" variant="secondary" class="ml-1 bg-success-soft text-success">Activo</Badge>
                    </p>
                </div>

                <div class="flex items-center gap-1 text-xs">
                    <span class="rounded-full bg-success-soft px-2 py-0.5 text-success">{{ resumen.confirmados }} listos</span>
                    <span v-if="resumen.porConfirmar" class="rounded-full bg-warning-soft px-2 py-0.5 text-warning-foreground">{{ resumen.porConfirmar }} por confirmar</span>
                    <span v-if="resumen.sinDato" class="rounded-full bg-danger-soft px-2 py-0.5 text-danger">{{ resumen.sinDato }} sin dato</span>
                </div>

                <div class="flex items-center gap-0.5 border-l pl-2">
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
                    <Button size="icon" variant="ghost" :class="cuadricula && 'bg-primary/10 text-primary'" aria-label="Cuadrícula" @click="cuadricula = !cuadricula"><Grid3x3 class="size-4" /></Button>
                    <Button size="icon" variant="ghost" :class="regla && 'bg-primary/10 text-primary'" aria-label="Regla" @click="regla = !regla"><Ruler class="size-4" /></Button>
                </div>

                <div v-if="esPdf && seleccion.length > 1" class="flex items-center gap-0.5 border-l pl-2">
                    <Button size="icon" variant="ghost" aria-label="Alinear a la izquierda" @click="alinear('izquierda')"><AlignStartVertical class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Centrar" @click="alinear('centro')"><AlignCenterHorizontal class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Alinear a la derecha" @click="alinear('derecha')"><AlignEndVertical class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Distribuir" @click="distribuir"><AlignHorizontalSpaceAround class="size-4" /></Button>
                </div>
                <div v-if="esPdf && seleccion.length" class="flex items-center gap-0.5 border-l pl-2">
                    <Button size="icon" variant="ghost" aria-label="Traer al frente" @click="orden(true)"><BringToFront class="size-4" /></Button>
                    <Button size="icon" variant="ghost" aria-label="Enviar atrás" @click="orden(false)"><SendToBack class="size-4" /></Button>
                </div>

                <div class="flex flex-wrap items-center gap-1.5 border-l pl-2">
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button size="sm" variant="ghost"><History class="size-4" /> v{{ datos.master.version }}</Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-56">
                            <DropdownMenuLabel>Versiones</DropdownMenuLabel>
                            <DropdownMenuItem v-for="v in datos.versiones" :key="v.id" as="a" :href="editorRuta.url(v.id)">
                                v{{ v.version }} · {{ v.estado_etiqueta }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem @click="subirAbierto = true"><Upload class="size-4" /> Subir nueva versión</DropdownMenuItem>
                            <DropdownMenuItem :disabled="!editable || detectando" @click="detectarEspacios"><ScanSearch class="size-4" /> Detectar espacios</DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                    <Button size="sm" :disabled="!editable || !sucio || guardando" @click="guardar">
                        <Loader2 v-if="guardando" class="size-4 animate-spin" /><Save v-else class="size-4" /> Guardar
                    </Button>
                    <Button size="sm" variant="outline" :disabled="probandoLargos" @click="probarDatosLargos">
                        <Loader2 v-if="probandoLargos" class="size-4 animate-spin" /><TextCursorInput v-else class="size-4" /> Datos largos
                    </Button>
                    <Button size="sm" variant="outline" @click="pruebaAbierta = true"><UserRoundSearch class="size-4" /> Probar colaborador</Button>
                    <Button size="sm" variant="default" class="bg-success text-success-foreground hover:bg-success/90" :disabled="!datos.preparacion.puede_activar || datos.master.activo || activando" @click="activar">
                        <Loader2 v-if="activando" class="size-4 animate-spin" /><ShieldCheck v-else class="size-4" />
                        {{ datos.master.activo ? 'Versión activa' : 'Activar versión' }}
                    </Button>
                </div>
            </header>

            <div v-if="!editable && datos.master.motivo_no_editable" class="flex flex-wrap items-center gap-3 rounded-xl bg-info-soft px-4 py-2 text-sm">
                <span class="mr-auto">{{ datos.master.motivo_no_editable }}</span>
                <Button size="sm" variant="outline" :disabled="copiando" @click="editarEnVersionNueva">
                    <Loader2 v-if="copiando" class="size-4 animate-spin" /><CopyPlus v-else class="size-4" /> Editar en versión nueva
                </Button>
            </div>
            <p v-if="datos.master.aviso_deteccion && editable" class="rounded-xl bg-warning-soft px-4 py-2 text-sm text-warning-foreground">
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
                                            c.zona !== 'dato' ? 'bg-muted text-muted-foreground' : c.estado === 'confirmado' ? 'bg-success-soft text-success' : c.estado === 'sugerido' ? 'bg-warning-soft text-warning-foreground' : 'bg-danger-soft text-danger',
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
                        @antes-de-cambiar="historial.registrar()"
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
                            <ChecklistPreparacion :preparacion="datos.preparacion" />
                            <div v-if="largos" class="mt-4 rounded-lg border p-3 text-sm">
                                <p :class="largos.ok ? 'font-medium text-success' : 'font-medium text-warning-foreground'">
                                    {{ largos.ok ? '✓ Todo cabe' : `⚠ ${largos.campos.length} campo(s) necesitan atención` }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ largos.mensaje }}</p>
                                <ul v-if="largos.campos.length" class="mt-2 flex flex-col gap-1">
                                    <li v-for="c in largos.campos" :key="`${c.field_key}-${c.etiqueta}`">
                                        <button type="button" class="w-full rounded-md bg-warning-soft px-2 py-1 text-left text-xs text-warning-foreground hover:ring-1 hover:ring-warning" @click="irA(c.field_key)">
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
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Probar con colaborador</DialogTitle>
                <DialogDescription>Vista previa con sus datos reales. No se guarda en su expediente.</DialogDescription>
            </DialogHeader>
            <BuscadorColaborador v-model="colaborador" />
            <div v-if="prueba" class="flex flex-col gap-2 rounded-lg border p-3 text-sm">
                <p>
                    {{ prueba.paginas.generado }} página(s) · {{ prueba.campos.llenos }} de {{ prueba.campos.total }} datos ·
                    <span :class="prueba.desbordes.length ? 'text-warning-foreground' : 'text-success'">{{ prueba.desbordes.length ? `${prueba.desbordes.length} desborde(s)` : 'sin desbordes' }}</span>
                </p>
                <p v-if="prueba.faltantes.length" class="text-xs text-warning-foreground">
                    Faltan {{ prueba.faltantes.length }} dato(s): {{ prueba.faltantes.join(', ') }}
                </p>
                <a :href="prueba.url_resultado" target="_blank" rel="noopener" class="text-primary underline">Abrir PDF de prueba</a>
            </div>
            <DialogFooter>
                <Button variant="outline" @click="pruebaAbierta = false">Cerrar</Button>
                <Button :disabled="!colaborador || probando" @click="probarColaborador">
                    <Loader2 v-if="probando" class="size-4 animate-spin" /> Generar vista previa
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
