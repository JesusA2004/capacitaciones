<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Archive,
    ArrowLeft,
    Check,
    Eye,
    FilePlus2,
    History,
    Lock,
    MousePointerClick,
    PenLine,
    Plus,
    Save,
    Search,
    Send,
    Sparkles,
    Trash2,
    Type,
    ZoomIn,
    ZoomOut,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import VisorPdfFormato from '@/components/Rh/formatos/VisorPdfFormato.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { enviarJson } from '@/lib/http';
import type { BloqueTexto } from '@/lib/pdf';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import {
    archivar,
    index as indexFormatos,
    reactivar,
    show as showFormato,
} from '@/routes/rh/formatos-oficiales';
import {
    analisis as analisisVersion,
    campos as camposVersion,
    descartar,
    publicar,
    store as storeVersion,
    vistaPrevia as vistaPreviaVersion,
} from '@/routes/rh/formatos-oficiales/versiones';
import type {
    AnalisisFormato,
    CampoFormato,
    GrupoVariables,
    SugerenciaCampo,
    VariableFormato,
    VersionFormatoEditor,
    VersionFormatoResumen,
} from '@/types';

const props = defineProps<{
    formato: {
        id: number;
        nombre: string;
        tipo_etiqueta: string;
        aplica_a: string;
        archivado: boolean;
        version_vigente_id: number | null;
    };
    version: VersionFormatoEditor;
    versiones: VersionFormatoResumen[];
    grupos: GrupoVariables[];
    formatosPorTipo: Record<string, Record<string, string>>;
    permisos: { configurar: boolean; versionar: boolean; archivar: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Formatos', href: indexFormatos() },
            { title: 'Editor', href: '' },
        ],
    },
});

const { confirmarAccion, mostrarError } = useAlertas();

const clonar = (lista: CampoFormato[]) => JSON.parse(JSON.stringify(lista)) as CampoFormato[];
const campos = ref<CampoFormato[]>(clonar(props.version.campos));
const guardados = ref(JSON.stringify(props.version.campos));
const sucio = computed(() => JSON.stringify(campos.value) !== guardados.value);
const editable = computed(() => props.version.editable && props.permisos.configurar);
const esOverlay = computed(() => props.version.estrategia === 'overlay');

watch(
    () => props.version,
    (v) => {
        campos.value = clonar(v.campos);
        guardados.value = JSON.stringify(v.campos);
        analisis.value = v.analisis;
        refinado.value = false;
    },
);

// --- Catálogo de variables ------------------------------------------------

const variables = computed(() => {
    const mapa = new Map<string, VariableFormato>();
    props.grupos.forEach((g) => g.variables.forEach((v) => mapa.set(v.clave, v)));

    return mapa;
});
const busqueda = ref('');

function normalizar(texto: string): string {
    return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
}

const gruposFiltrados = computed(() => {
    const termino = normalizar(busqueda.value.trim());

    if (termino === '') {
        return props.grupos;
    }

    return props.grupos
        .map((g) => ({
            ...g,
            variables: g.variables.filter(
                (v) =>
                    normalizar(v.etiqueta).includes(termino) ||
                    v.clave.includes(termino) ||
                    v.sinonimos.some((s) => normalizar(s).includes(termino)),
            ),
        }))
        .filter((g) => g.variables.length > 0);
});

function etiquetaCampo(campo: CampoFormato): string {
    if (campo.tipo === 'manual') {
        return campo.etiqueta ?? 'Dato manual';
    }

    if (campo.tipo === 'texto') {
        return campo.texto ?? 'Texto fijo';
    }

    return variables.value.get(campo.variable ?? '')?.etiqueta ?? campo.variable ?? '—';
}

function tipoVariable(campo: CampoFormato): string {
    if (campo.tipo === 'manual' || campo.tipo === 'texto') {
        return 'texto';
    }

    return variables.value.get(campo.variable ?? '')?.tipo ?? 'texto';
}

// --- Selección y colocación ------------------------------------------------

const seleccionadoId = ref<string | null>(null);
const seleccionado = computed(() => campos.value.find((c) => c.id === seleccionadoId.value) ?? null);
type Pendiente = { tipo: CampoFormato['tipo']; variable: string | null; etiqueta: string | null; texto: string | null };
const pendiente = ref<Pendiente | null>(null);
const zoom = ref(1);
const paginasMm = ref<Record<number, { ancho: number; alto: number }>>({});

function nuevoId(): string {
    return Math.random().toString(36).slice(2, 12);
}

function prepararColocacion(p: Pendiente) {
    if (!editable.value) {
        return;
    }

    pendiente.value = p;
    toast.info('Haz clic en el documento donde va el dato.');
}

function elegirVariable(variable: VariableFormato) {
    prepararColocacion({ tipo: variable.tipo === 'imagen' ? 'imagen' : 'variable', variable: variable.clave, etiqueta: null, texto: null });
}

function colocar(evento: MouseEvent, pagina: number, escala: number) {
    if (!pendiente.value) {
        seleccionadoId.value = null;

        return;
    }

    const capa = evento.currentTarget as HTMLElement;
    const caja = capa.getBoundingClientRect();
    const x = (evento.clientX - caja.left) / escala;
    const y = (evento.clientY - caja.top) / escala;
    const esImagen = pendiente.value.tipo === 'imagen';

    const campo: CampoFormato = {
        id: nuevoId(),
        tipo: pendiente.value.tipo,
        variable: pendiente.value.variable,
        etiqueta: pendiente.value.etiqueta,
        texto: pendiente.value.texto,
        pagina,
        x: redondear(x),
        y: redondear(Math.max(0, y - 3)),
        ancho: esImagen ? 30 : 70,
        alto: esImagen ? 35 : 6,
        font_size: 10,
        align: 'left',
        negrita: false,
        color: '#111111',
        formato: null,
        max_caracteres: null,
        multilinea: false,
        requerido: pendiente.value.tipo === 'variable' || pendiente.value.tipo === 'manual',
    };

    campos.value.push(campo);
    seleccionadoId.value = campo.id;
    pendiente.value = null;
}

function eliminarSeleccionado() {
    campos.value = campos.value.filter((c) => c.id !== seleccionadoId.value);
    seleccionadoId.value = null;
}

function redondear(valor: number): number {
    return Math.round(valor * 10) / 10;
}

// Arrastrar (mover) y redimensionar con el puntero.
type Arrastre = { id: string; modo: 'mover' | 'tamano'; inicioX: number; inicioY: number; x: number; y: number; ancho: number; alto: number; escala: number };
let arrastre: Arrastre | null = null;

function iniciarArrastre(evento: PointerEvent, campo: CampoFormato, modo: 'mover' | 'tamano', escala: number) {
    seleccionadoId.value = campo.id;

    if (!editable.value) {
        return;
    }

    evento.stopPropagation();
    evento.preventDefault();
    arrastre = { id: campo.id, modo, inicioX: evento.clientX, inicioY: evento.clientY, x: campo.x, y: campo.y, ancho: campo.ancho, alto: campo.alto, escala };
    window.addEventListener('pointermove', moverArrastre);
    window.addEventListener('pointerup', terminarArrastre, { once: true });
}

function moverArrastre(evento: PointerEvent) {
    if (!arrastre) {
        return;
    }

    const campo = campos.value.find((c) => c.id === arrastre?.id);

    if (!campo) {
        return;
    }

    const dx = (evento.clientX - arrastre.inicioX) / arrastre.escala;
    const dy = (evento.clientY - arrastre.inicioY) / arrastre.escala;
    const pagina = paginasMm.value[campo.pagina];

    if (arrastre.modo === 'mover') {
        campo.x = redondear(Math.max(0, Math.min((pagina?.ancho ?? 999) - 2, arrastre.x + dx)));
        campo.y = redondear(Math.max(0, Math.min((pagina?.alto ?? 999) - 2, arrastre.y + dy)));
    } else {
        campo.ancho = redondear(Math.max(4, arrastre.ancho + dx));
        campo.alto = redondear(Math.max(3, arrastre.alto + dy));
    }
}

function terminarArrastre() {
    arrastre = null;
    window.removeEventListener('pointermove', moverArrastre);
}

onBeforeUnmount(terminarArrastre);

// --- Detección automática ----------------------------------------------------

const analisis = ref<AnalisisFormato>(props.version.analisis);
const refinado = ref(false);
const aceptadas = ref<Set<string>>(new Set());

const sugerenciasPendientes = computed(() =>
    analisis.value.sugerencias.filter(
        (s) => !campos.value.some((c) => c.variable === s.variable && c.pagina === s.pagina && Math.abs(c.y - s.y) < 4),
    ),
);

watch(
    () => analisis.value.sugerencias,
    (lista) => {
        aceptadas.value = new Set(lista.filter((s) => s.estado === 'seguro').map((s) => s.id));
    },
    { immediate: true },
);

async function alCargarVisor(paginas: { numero: number; ancho: number; alto: number }[], bloques: BloqueTexto[]) {
    paginasMm.value = Object.fromEntries(paginas.map((p) => [p.numero, { ancho: p.ancho, alto: p.alto }]));

    // Posiciones exactas del visor → el backend rehace la detección.
    if (!editable.value || !esOverlay.value || refinado.value || bloques.length === 0 || analisis.value.metodo === 'ocr') {
        return;
    }

    refinado.value = true;

    try {
        const respuesta = await enviarJson<{ analisis: AnalisisFormato }>('POST', analisisVersion.url(props.version.id), { bloques });
        analisis.value = respuesta.analisis;
    } catch {
        // Se conserva el análisis del servidor.
    }
}

function agregarSugerencias() {
    const nuevas = sugerenciasPendientes.value.filter((s) => aceptadas.value.has(s.id));

    nuevas.forEach((s: SugerenciaCampo) => {
        campos.value.push({
            id: nuevoId(),
            tipo: 'variable',
            variable: s.variable,
            etiqueta: null,
            texto: null,
            pagina: s.pagina,
            x: s.x,
            y: s.y,
            ancho: s.ancho,
            alto: s.alto,
            font_size: s.font_size,
            align: 'left',
            negrita: false,
            color: '#111111',
            formato: null,
            max_caracteres: null,
            multilinea: false,
            requerido: true,
        });
    });

    toast.success(`${nuevas.length} campo(s) agregados. Revisa su posición antes de guardar.`);
}

function alternarSugerencia(id: string, valor: boolean) {
    const conjunto = new Set(aceptadas.value);

    if (valor) {
        conjunto.add(id);
    } else {
        conjunto.delete(id);
    }

    aceptadas.value = conjunto;
}

// --- Guardar / vista previa / publicar ------------------------------------------

const guardando = ref(false);
const previsualizando = ref(false);
const pdfPrevia = ref<string | null>(null);

async function guardar(): Promise<boolean> {
    guardando.value = true;

    try {
        const respuesta = await enviarJson<{ campos: CampoFormato[] }>('PUT', camposVersion.url(props.version.id), { campos: campos.value });
        campos.value = clonar(respuesta.campos);
        guardados.value = JSON.stringify(respuesta.campos);
        toast.success('Mapeo guardado.');

        return true;
    } catch (e) {
        await mostrarError(e instanceof Error ? e.message : 'No se pudo guardar el mapeo.');

        return false;
    } finally {
        guardando.value = false;
    }
}

async function verVistaPrevia() {
    previsualizando.value = true;

    try {
        const respuesta = await enviarJson<{ pdf_base64: string }>('POST', vistaPreviaVersion.url(props.version.id), { campos: campos.value });
        pdfPrevia.value = `data:application/pdf;base64,${respuesta.pdf_base64}`;
    } catch (e) {
        await mostrarError(e instanceof Error ? e.message : 'No se pudo generar la vista previa.');
    } finally {
        previsualizando.value = false;
    }
}

async function publicarVersion() {
    if (sucio.value && !(await guardar())) {
        return;
    }

    const requeridos = campos.value.filter((c) => c.requerido).length;
    const confirmado = await confirmarAccion(
        `¿Publicar la versión ${props.version.numero}?`,
        `A partir de ahora los documentos nuevos se generan con esta versión (${campos.value.length} campos, ${requeridos} requeridos). La versión anterior se conserva para los documentos ya generados. Una versión publicada ya no se puede editar.`,
        'Sí, publicar',
    );

    if (confirmado) {
        router.post(publicar.url(props.version.id), {}, { preserveScroll: true });
    }
}

async function descartarBorrador() {
    const confirmado = await confirmarAccion(
        `¿Descartar el borrador v${props.version.numero}?`,
        'Se elimina este borrador y su mapeo. Las versiones publicadas no cambian.',
        'Sí, descartar',
    );

    if (confirmado) {
        router.delete(descartar.url(props.version.id));
    }
}

async function alternarArchivo() {
    if (props.formato.archivado) {
        router.post(reactivar.url(props.formato.id), {}, { preserveScroll: true });

        return;
    }

    const confirmado = await confirmarAccion(
        '¿Archivar este formato?',
        'Deja de ofrecerse para generar documentos nuevos. Sus versiones y los documentos ya generados se conservan.',
        'Sí, archivar',
    );

    if (confirmado) {
        router.post(archivar.url(props.formato.id), {}, { preserveScroll: true });
    }
}

// Versión nueva (mismo archivo o uno nuevo).
const dialogoVersion = ref(false);
const archivoVersion = ref<File | null>(null);
const notasVersion = ref('');
const creandoVersion = ref(false);

function crearVersion() {
    creandoVersion.value = true;
    router.post(
        storeVersion.url(props.formato.id),
        { archivo: archivoVersion.value, notas: notasVersion.value || null },
        {
            forceFormData: true,
            onFinish: () => {
                creandoVersion.value = false;
                dialogoVersion.value = false;
            },
        },
    );
}

function irAVersion(id: number) {
    router.get(showFormato.url(props.formato.id), { version: id }, { preserveScroll: true });
}

function fecha(valor: string | null): string {
    return valor ? new Date(valor).toLocaleDateString('es-MX', { day: 'numeric', month: 'short', year: 'numeric' }) : '—';
}

const formatosDelSeleccionado = computed(() => (seleccionado.value ? props.formatosPorTipo[tipoVariable(seleccionado.value)] ?? {} : {}));

// Envolturas string ↔ null para los inputs del campo seleccionado.
function proxy(clave: 'etiqueta' | 'texto' | 'formato' | 'variable') {
    return computed({
        get: () => seleccionado.value?.[clave] ?? '',
        set: (valor: string) => {
            if (seleccionado.value) {
                seleccionado.value[clave] = valor === '' ? null : valor;
            }
        },
    });
}

const etiquetaSel = proxy('etiqueta');
const textoSel = proxy('texto');
const formatoSel = proxy('formato');
const variableSel = proxy('variable');
const maxCaracteresSel = computed({
    get: () => (seleccionado.value?.max_caracteres ?? '') as number | string,
    set: (valor: number | string) => {
        if (seleccionado.value) {
            seleccionado.value.max_caracteres = valor === '' || valor === null ? null : Number(valor);
        }
    },
});

function cambiarDatoDocx(campo: CampoFormato, evento: Event) {
    const valor = (evento.target as HTMLSelectElement).value;

    if (valor === '__manual__') {
        campo.tipo = 'manual';
        campo.variable = null;
        campo.etiqueta ??= campo.placeholder ?? 'Dato manual';
    } else {
        campo.tipo = 'variable';
        campo.variable = valor;
    }
}

function cambiarFormatoDocx(campo: CampoFormato, evento: Event) {
    const valor = (evento.target as HTMLSelectElement).value;
    campo.formato = valor === '' ? null : valor;
}

const claseSelect = 'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs disabled:opacity-50';
const borradorExistente = computed(() => props.versiones.find((v) => v.estado === 'borrador') ?? null);
const panel = ref<'variables' | 'detectados'>(props.version.analisis.sugerencias.length > 0 && props.version.editable ? 'detectados' : 'variables');
</script>

<template>
    <Head :title="`Editor · ${formato.nombre}`" />

    <div class="flex w-full min-w-0 flex-col gap-4 p-4 sm:p-6">
        <!-- Encabezado de detalle -->
        <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-start gap-3">
                <Button as-child variant="ghost" size="icon" class="shrink-0">
                    <Link :href="indexFormatos()" aria-label="Volver a formatos"><ArrowLeft class="size-4" /></Link>
                </Button>
                <div class="min-w-0">
                    <h1 class="truncate text-xl font-semibold tracking-tight">{{ formato.nombre }}</h1>
                    <p class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>{{ formato.tipo_etiqueta }}</span>
                        <span aria-hidden="true">·</span>
                        <span>v{{ version.numero }}</span>
                        <Badge :variant="version.estado === 'publicada' ? 'default' : 'outline'">{{ version.estado_etiqueta }}</Badge>
                        <Badge v-if="formato.archivado" variant="outline">Archivado</Badge>
                        <span class="truncate text-xs">{{ version.original_filename }}</span>
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button variant="outline" size="sm" :disabled="previsualizando || campos.length === 0" @click="verVistaPrevia">
                    <Spinner v-if="previsualizando" />
                    <Eye v-else class="size-4" />
                    Vista previa
                </Button>
                <Button v-if="editable" size="sm" variant="outline" :disabled="!sucio || guardando" @click="guardar">
                    <Spinner v-if="guardando" />
                    <Save v-else class="size-4" />
                    Guardar
                </Button>
                <Button v-if="version.estado === 'borrador' && permisos.versionar" size="sm" @click="publicarVersion">
                    <Send class="size-4" />
                    Publicar versión
                </Button>
                <Button v-if="permisos.versionar && !borradorExistente && !formato.archivado" size="sm" variant="outline" @click="dialogoVersion = true">
                    <FilePlus2 class="size-4" />
                    Nueva versión
                </Button>
                <Button v-if="version.estado === 'borrador' && permisos.versionar && versiones.length > 1" size="sm" variant="ghost" @click="descartarBorrador">
                    <Trash2 class="size-4" />
                    Descartar borrador
                </Button>
                <Button v-if="permisos.archivar" size="sm" variant="ghost" @click="alternarArchivo">
                    <Archive class="size-4" />
                    {{ formato.archivado ? 'Reactivar' : 'Archivar' }}
                </Button>
            </div>
        </div>

        <!-- Avisos -->
        <div v-if="!version.editable" class="flex items-start gap-2 rounded-xl border bg-muted/40 p-3 text-sm">
            <Lock class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <span>
                Esta versión es {{ version.estado_etiqueta.toLowerCase() }} y no se modifica: los documentos generados con ella se
                reproducen tal cual.
                <template v-if="borradorExistente">
                    Hay un borrador (v{{ borradorExistente.numero }}):
                    <button class="font-medium text-primary underline" @click="irAVersion(borradorExistente.id)">ábrelo</button>.
                </template>
                <template v-else-if="permisos.versionar">Para cambiarla crea una <b>nueva versión</b>.</template>
            </span>
        </div>
        <div v-if="version.fidelidad === 'aproximada'" class="flex items-start gap-2 rounded-xl border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-amber-600" />
            <span>
                Este Word se convirtió a PDF sin LibreOffice: el diseño puede variar respecto al original. Para una copia fiel,
                sube el formato como PDF (Guardar como PDF en Word) o pide a sistemas configurar LibreOffice.
            </span>
        </div>
        <div v-for="mensaje in analisis.mensajes" :key="mensaje" class="rounded-xl border border-dashed p-3 text-sm text-muted-foreground">
            {{ mensaje }}
        </div>

        <!-- Estrategia overlay: documento + panel -->
        <div v-if="esOverlay" class="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 rounded-2xl border bg-muted/30 p-3">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <p v-if="pendiente" class="flex items-center gap-2 text-sm font-medium text-primary">
                        <MousePointerClick class="size-4" />
                        Clic en el documento para colocar «{{ pendiente.variable ? variables.get(pendiente.variable)?.etiqueta : pendiente.etiqueta ?? 'texto' }}»
                        <button class="text-xs text-muted-foreground underline" @click="pendiente = null">cancelar</button>
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ campos.length }} campo(s) · arrastra para mover, esquina inferior para cambiar tamaño
                    </p>
                    <div class="flex items-center gap-1">
                        <Button size="icon" variant="ghost" :disabled="zoom <= 0.6" aria-label="Alejar" @click="zoom = Math.max(0.6, zoom - 0.2)"><ZoomOut class="size-4" /></Button>
                        <span class="w-12 text-center text-xs tabular-nums">{{ Math.round(zoom * 100) }}%</span>
                        <Button size="icon" variant="ghost" :disabled="zoom >= 2" aria-label="Acercar" @click="zoom = Math.min(2, zoom + 0.2)"><ZoomIn class="size-4" /></Button>
                    </div>
                </div>

                <VisorPdfFormato :url="version.archivo_url" :zoom="zoom" @cargado="alCargarVisor">
                    <template #capa="{ pagina, escala }">
                        <div
                            :class="cn('absolute inset-0', pendiente && 'cursor-crosshair')"
                            @click="colocar($event, pagina, escala)"
                        >
                            <div
                                v-for="campo in campos.filter((c) => c.pagina === pagina)"
                                :key="campo.id"
                                :class="
                                    cn(
                                        'absolute flex items-center overflow-hidden rounded-[3px] border px-0.5 text-[10px] leading-none select-none',
                                        campo.tipo === 'manual' ? 'border-amber-500 bg-amber-400/20 text-amber-900' : campo.tipo === 'texto' ? 'border-slate-500 bg-slate-400/15 text-slate-800' : 'border-primary bg-primary/15 text-emerald-950',
                                        seleccionadoId === campo.id && 'ring-2 ring-sky-500',
                                        editable && 'cursor-move',
                                    )
                                "
                                :style="{
                                    left: `${campo.x * escala}px`,
                                    top: `${campo.y * escala}px`,
                                    width: `${campo.ancho * escala}px`,
                                    height: `${campo.alto * escala}px`,
                                    justifyContent: campo.align === 'center' ? 'center' : campo.align === 'right' ? 'flex-end' : 'flex-start',
                                }"
                                :title="etiquetaCampo(campo)"
                                @click.stop="seleccionadoId = campo.id"
                                @pointerdown="iniciarArrastre($event, campo, 'mover', escala)"
                            >
                                <span class="truncate" :class="campo.negrita && 'font-bold'">
                                    <span v-if="campo.requerido" class="text-destructive">*</span>{{ etiquetaCampo(campo) }}
                                </span>
                                <span
                                    v-if="editable"
                                    class="absolute right-0 bottom-0 size-2.5 cursor-se-resize rounded-tl bg-sky-500"
                                    @pointerdown="iniciarArrastre($event, campo, 'tamano', escala)"
                                />
                            </div>
                        </div>
                    </template>
                </VisorPdfFormato>
            </div>

            <!-- Panel lateral -->
            <aside class="flex min-w-0 flex-col gap-3">
                <!-- Propiedades del campo seleccionado -->
                <section v-if="seleccionado" class="rounded-2xl border p-3">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h2 class="truncate text-sm font-semibold">{{ etiquetaCampo(seleccionado) }}</h2>
                        <Button v-if="editable" size="icon" variant="ghost" aria-label="Quitar campo" @click="eliminarSeleccionado">
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                    <fieldset :disabled="!editable" class="grid grid-cols-2 gap-2 text-xs">
                        <div v-if="seleccionado.tipo === 'manual'" class="col-span-2 grid gap-1">
                            <Label class="text-xs">Nombre del dato (se pedirá al generar)</Label>
                            <Input v-model="etiquetaSel" class="h-8" />
                        </div>
                        <div v-if="seleccionado.tipo === 'texto'" class="col-span-2 grid gap-1">
                            <Label class="text-xs">Texto fijo</Label>
                            <Input v-model="textoSel" class="h-8" />
                        </div>
                        <div v-if="seleccionado.tipo === 'variable'" class="col-span-2 grid gap-1">
                            <Label class="text-xs">Dato del sistema</Label>
                            <NativeSelect v-model="variableSel" class="h-8 text-xs">
                                <optgroup v-for="grupo in grupos" :key="grupo.clave" :label="grupo.etiqueta">
                                    <option v-for="v in grupo.variables.filter((x) => x.tipo !== 'imagen')" :key="v.clave" :value="v.clave">{{ v.etiqueta }}</option>
                                </optgroup>
                            </NativeSelect>
                        </div>
                        <div class="grid gap-1"><Label class="text-xs">X (mm)</Label><Input v-model.number="seleccionado.x" type="number" step="0.5" class="h-8" /></div>
                        <div class="grid gap-1"><Label class="text-xs">Y (mm)</Label><Input v-model.number="seleccionado.y" type="number" step="0.5" class="h-8" /></div>
                        <div class="grid gap-1"><Label class="text-xs">Ancho (mm)</Label><Input v-model.number="seleccionado.ancho" type="number" step="0.5" min="2" class="h-8" /></div>
                        <div class="grid gap-1"><Label class="text-xs">Alto (mm)</Label><Input v-model.number="seleccionado.alto" type="number" step="0.5" min="2" class="h-8" /></div>
                        <template v-if="seleccionado.tipo !== 'imagen'">
                            <div class="grid gap-1"><Label class="text-xs">Tamaño de letra</Label><Input v-model.number="seleccionado.font_size" type="number" step="0.5" min="5" max="48" class="h-8" /></div>
                            <div class="grid gap-1">
                                <Label class="text-xs">Alineación</Label>
                                <NativeSelect v-model="seleccionado.align" class="h-8 text-xs">
                                    <option value="left">Izquierda</option>
                                    <option value="center">Centro</option>
                                    <option value="right">Derecha</option>
                                </NativeSelect>
                            </div>
                            <div v-if="Object.keys(formatosDelSeleccionado).length > 0 && seleccionado.tipo === 'variable'" class="col-span-2 grid gap-1">
                                <Label class="text-xs">Formato</Label>
                                <NativeSelect v-model="formatoSel" class="h-8 text-xs">
                                    <option value="">Predeterminado</option>
                                    <option v-for="(etiqueta, clave) in formatosDelSeleccionado" :key="clave" :value="clave">{{ etiqueta }}</option>
                                </NativeSelect>
                            </div>
                            <div class="grid gap-1">
                                <Label class="text-xs">Máx. caracteres</Label>
                                <Input v-model="maxCaracteresSel" type="number" min="1" class="h-8" />
                            </div>
                            <div class="grid gap-1"><Label class="text-xs">Color</Label><Input v-model="seleccionado.color" type="color" class="h-8 p-1" /></div>
                            <label class="flex items-center gap-2"><Checkbox :model-value="seleccionado.negrita" @update:model-value="(v) => seleccionado && (seleccionado.negrita = !!v)" />Negritas</label>
                            <label class="flex items-center gap-2"><Checkbox :model-value="seleccionado.multilinea" @update:model-value="(v) => seleccionado && (seleccionado.multilinea = !!v)" />Varias líneas</label>
                        </template>
                        <label v-if="seleccionado.tipo !== 'texto'" class="col-span-2 flex items-center gap-2">
                            <Checkbox :model-value="seleccionado.requerido" @update:model-value="(v) => seleccionado && (seleccionado.requerido = !!v)" />
                            Requerido (no se genera si falta)
                        </label>
                        <div class="grid gap-1">
                            <Label class="text-xs">Página</Label>
                            <Input v-model.number="seleccionado.pagina" type="number" min="1" :max="version.paginas.length || 1" class="h-8" />
                        </div>
                    </fieldset>
                </section>

                <!-- Pestañas: campos del sistema / detectados -->
                <section class="flex min-h-0 flex-col rounded-2xl border">
                    <div class="flex border-b text-sm">
                        <button :class="cn('flex-1 px-3 py-2 font-medium', panel === 'variables' ? 'border-b-2 border-primary text-foreground' : 'text-muted-foreground')" @click="panel = 'variables'">
                            Campos del sistema
                        </button>
                        <button :class="cn('flex-1 px-3 py-2 font-medium', panel === 'detectados' ? 'border-b-2 border-primary text-foreground' : 'text-muted-foreground')" @click="panel = 'detectados'">
                            Detectados ({{ sugerenciasPendientes.length }})
                        </button>
                    </div>

                    <div v-if="panel === 'variables'" class="flex max-h-[70vh] flex-col gap-2 overflow-y-auto p-3">
                        <div class="relative">
                            <Search class="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                            <Input v-model="busqueda" placeholder="Buscar campo…" class="h-9 pl-8" />
                        </div>
                        <div v-if="editable" class="flex flex-wrap gap-2">
                            <Button size="sm" variant="outline" @click="prepararColocacion({ tipo: 'manual', variable: null, etiqueta: 'Dato manual', texto: null })">
                                <PenLine class="size-4" />
                                Campo manual
                            </Button>
                            <Button size="sm" variant="outline" @click="prepararColocacion({ tipo: 'texto', variable: null, etiqueta: null, texto: 'Texto' })">
                                <Type class="size-4" />
                                Texto fijo
                            </Button>
                        </div>
                        <div v-for="grupo in gruposFiltrados" :key="grupo.clave">
                            <p class="mt-2 mb-1 text-xs font-semibold tracking-wide text-muted-foreground uppercase">{{ grupo.etiqueta }}</p>
                            <button
                                v-for="variable in grupo.variables"
                                :key="variable.clave"
                                :disabled="!editable"
                                class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left text-sm hover:bg-muted disabled:cursor-default disabled:hover:bg-transparent"
                                @click="elegirVariable(variable)"
                            >
                                <span class="min-w-0">
                                    <span class="block truncate">{{ variable.etiqueta }}</span>
                                    <span class="block truncate text-[11px] text-muted-foreground">{{ variable.clave }}</span>
                                </span>
                                <Plus v-if="editable" class="size-4 shrink-0 text-muted-foreground" />
                            </button>
                        </div>
                    </div>

                    <div v-else class="flex max-h-[70vh] flex-col gap-2 overflow-y-auto p-3">
                        <p v-if="sugerenciasPendientes.length === 0" class="text-sm text-muted-foreground">
                            No hay campos detectados pendientes. Agrega los datos desde «Campos del sistema».
                        </p>
                        <template v-else>
                            <p class="text-sm">
                                Se detectaron <b>{{ sugerenciasPendientes.length }}</b> posibles campos. Marca los correctos y agrégalos; después ajusta su posición.
                            </p>
                            <label
                                v-for="s in sugerenciasPendientes"
                                :key="s.id"
                                class="flex items-start gap-2 rounded-lg border p-2 text-sm"
                            >
                                <Checkbox :disabled="!editable" :model-value="aceptadas.has(s.id)" @update:model-value="(v) => alternarSugerencia(s.id, !!v)" />
                                <span class="min-w-0 flex-1">
                                    <span class="flex items-center gap-1">
                                        <Check v-if="s.estado === 'seguro'" class="size-3.5 text-primary" />
                                        <span v-else class="font-bold text-amber-600">?</span>
                                        <span class="truncate">«{{ s.etiqueta_detectada }}»</span>
                                    </span>
                                    <span class="block text-xs text-muted-foreground">→ {{ s.etiqueta_variable }} · pág. {{ s.pagina }}</span>
                                </span>
                            </label>
                            <Button v-if="editable" size="sm" :disabled="aceptadas.size === 0" @click="agregarSugerencias">
                                <Sparkles class="size-4" />
                                Agregar seleccionados
                            </Button>
                        </template>
                    </div>
                </section>

                <!-- Historial de versiones -->
                <section class="rounded-2xl border p-3">
                    <h2 class="mb-2 flex items-center gap-2 text-sm font-semibold"><History class="size-4" />Versiones</h2>
                    <ul class="flex flex-col gap-1 text-sm">
                        <li v-for="v in versiones" :key="v.id">
                            <button
                                :class="cn('flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-left hover:bg-muted', v.id === version.id && 'bg-muted')"
                                @click="irAVersion(v.id)"
                            >
                                <span>
                                    <b>v{{ v.numero }}</b> · {{ v.estado_etiqueta }}
                                    <span class="block text-xs text-muted-foreground">
                                        {{ v.publicada_en ? `Publicada ${fecha(v.publicada_en)} por ${v.publicada_por ?? '—'}` : `Creada ${fecha(v.creada_en)} por ${v.creada_por ?? 'sistema'}` }}
                                    </span>
                                </span>
                                <span class="shrink-0 text-xs text-muted-foreground">{{ v.generaciones }} doc.</span>
                            </button>
                        </li>
                    </ul>
                </section>
            </aside>
        </div>

        <!-- Estrategia Word con variables: tabla de marcadores -->
        <div v-else class="rounded-2xl border">
            <div class="border-b p-3 text-sm text-muted-foreground">
                Este Word ya trae marcadores <code v-pre>{{ ... }}</code>: se rellenan dentro del propio documento. Asigna a cada uno su dato
                del sistema o conviértelo en dato manual.
            </div>
            <div class="divide-y">
                <div v-for="campo in campos" :key="campo.id" class="grid grid-cols-1 items-center gap-2 p-3 text-sm md:grid-cols-[12rem_1fr_12rem_auto]">
                    <code class="truncate rounded bg-muted px-2 py-1 text-xs">{{ '{' + '{' + (campo.placeholder ?? '') + '}' + '}' }}</code>
                    <div class="flex gap-2">
                        <select
                            :value="campo.tipo === 'manual' ? '__manual__' : campo.variable ?? ''"
                            :disabled="!editable"
                            :class="claseSelect"
                            @change="cambiarDatoDocx(campo, $event)"
                        >
                            <option value="__manual__">Dato manual (se pide al generar)</option>
                            <optgroup v-for="grupo in grupos" :key="grupo.clave" :label="grupo.etiqueta">
                                <option v-for="v in grupo.variables.filter((x) => x.tipo !== 'imagen')" :key="v.clave" :value="v.clave">{{ v.etiqueta }}</option>
                            </optgroup>
                        </select>
                        <Input v-if="campo.tipo === 'manual'" :model-value="campo.etiqueta ?? ''" @update:model-value="(v: string | number) => (campo.etiqueta = String(v))" :disabled="!editable" class="h-9 flex-1" placeholder="Nombre del dato" />
                    </div>
                    <select
                        v-if="campo.tipo === 'variable'"
                        :value="campo.formato ?? ''"
                        :disabled="!editable"
                        :class="claseSelect"
                        @change="cambiarFormatoDocx(campo, $event)"
                    >
                        <option value="">Formato predeterminado</option>
                        <option v-for="(etiqueta, clave) in formatosPorTipo[tipoVariable(campo)] ?? {}" :key="clave" :value="clave">{{ etiqueta }}</option>
                    </select>
                    <span v-else />
                    <label class="flex items-center gap-2 whitespace-nowrap">
                        <Checkbox :disabled="!editable" :model-value="campo.requerido" @update:model-value="(v) => (campo.requerido = !!v)" />
                        Requerido
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Vista previa -->
    <Dialog :open="pdfPrevia !== null" @update:open="(v) => !v && (pdfPrevia = null)">
        <DialogContent class="flex max-h-[92vh] w-[calc(100vw-2rem)] flex-col sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Vista previa con datos de ejemplo</DialogTitle>
                <DialogDescription>Datos ficticios del catálogo, no de una persona real. Los campos manuales aparecen con su nombre entre «».</DialogDescription>
            </DialogHeader>
            <iframe v-if="pdfPrevia" :src="pdfPrevia" title="Vista previa" class="h-[70vh] w-full rounded-lg border" />
        </DialogContent>
    </Dialog>

    <!-- Nueva versión -->
    <Dialog v-model:open="dialogoVersion">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Nueva versión</DialogTitle>
                <DialogDescription>
                    Se crea un borrador. La versión vigente se sigue usando hasta que publiques la nueva. Sin archivo, se copia el
                    documento actual para reajustar el mapeo.
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-3">
                <div class="grid gap-1">
                    <Label>Archivo nuevo (opcional)</Label>
                    <Input type="file" accept=".pdf,.docx,.png,.jpg,.jpeg,.webp" @change="(e: Event) => (archivoVersion = (e.target as HTMLInputElement).files?.[0] ?? null)" />
                </div>
                <div class="grid gap-1">
                    <Label>Qué cambió</Label>
                    <Textarea v-model="notasVersion" rows="3" placeholder="Ej. Jurídico actualizó la cláusula 5" />
                </div>
            </div>
            <DialogFooter>
                <Button variant="secondary" @click="dialogoVersion = false">Cancelar</Button>
                <Button :disabled="creandoVersion" @click="crearVersion">
                    <Spinner v-if="creandoVersion" />
                    Crear borrador
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
