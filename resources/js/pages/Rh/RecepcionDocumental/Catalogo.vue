<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    CalendarCheck,
    FileStack,
    GripVertical,
    Loader2,
    PackageCheck,
    PackagePlus,
    Pencil,
    Plus,
    Settings2,
    Sparkles,
    ToggleRight,
    Trash2,
} from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import EmptyState from '@/components/Common/EmptyState.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InterruptorRecepcion from '@/components/recepcion-documental/InterruptorRecepcion.vue';
import KpiRecepcion from '@/components/recepcion-documental/KpiRecepcion.vue';
import SeleccionMultipleBuscable from '@/components/recepcion-documental/SeleccionMultipleBuscable.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import CambioSuave from '@/components/ui/CambioSuave.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { ComboboxBuscable } from '@/components/ui/combobox-buscable';
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
import NumeroAnimado from '@/components/ui/NumeroAnimado.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Skeleton } from '@/components/ui/skeleton';
import { Textarea } from '@/components/ui/textarea';
import { formatearFechaHora } from '@/lib/fechas';
import { enviarJson, ErrorSolicitud } from '@/lib/http';
import { dashboard } from '@/routes';
import { index as inicioRecepcion } from '@/routes/rh/recepcion-documental';
import {
    destroy as rutaEliminar,
    reordenar as rutaReordenar,
    simular as rutaSimular,
    store as rutaCrear,
    update as rutaActualizar,
    vistaPrevia as rutaVistaPrevia,
    vistaPreviaNueva as rutaVistaPreviaNueva,
} from '@/routes/rh/recepcion-documental/catalogo';
import type {
    EntradaCatalogoRecepcion,
    ModoCalculoRecepcion,
    OpcionesCatalogoRecepcion,
    RespuestaCatalogoRecepcion,
    SimulacionRecepcion,
    VistaPreviaRecepcion,
} from '@/types';

type Opcion = { value: number; label: string };

const props = defineProps<{
    entradas: EntradaCatalogoRecepcion[];
    previas: Record<number, VistaPreviaRecepcion>;
    periodoActual: { clave: string; etiqueta: string };
    sucursales: (Opcion & { empresa_id: number | null })[];
    empresas: Opcion[];
    departamentos: Opcion[];
    opciones: OpcionesCatalogoRecepcion;
    puedeConfigurar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recepción documental', href: inicioRecepcion() },
            { title: 'Lo que recibo', href: '' },
        ],
    },
});

// ───────────── Estado local (se actualiza con cada respuesta) ─────────────
const lista = ref<EntradaCatalogoRecepcion[]>([...props.entradas]);
const previas = ref<Record<number, VistaPreviaRecepcion>>({ ...props.previas });
const opciones = ref<OpcionesCatalogoRecepcion>(props.opciones);
const ordenando = ref(false);

watch(
    () => props.entradas,
    (nuevas) => (lista.value = [...nuevas]),
);
watch(
    () => props.previas,
    (nuevas) => (previas.value = { ...nuevas }),
);

function aplicarRespuesta(r: RespuestaCatalogoRecepcion) {
    lista.value = r.entradas;
    previas.value = r.previas;
    opciones.value = r.opciones;

    if (r.recalculo === null && r.cortes.length === 0) {
        toast.success('Cambios guardados.');

        return;
    }

    const resumen = r.cortes
        .map((c) => `${c.etiqueta}: ${c.antes} → ${c.despues}`)
        .join(' · ');
    toast.success(
        r.cortes.length
            ? `Recalculado. No. de papeles — ${resumen}.`
            : 'Cambios guardados. No hay cortes abiertos que recalcular.',
    );

    if (r.conflictos.length) {
        toast.warning(
            `${r.conflictos.length} original(es) ya marcado(s) no cuadran con la nueva configuración: se conservaron. Revísalos en el corte.`,
        );
    }
}

function mensajeError(e: unknown, defecto: string): string {
    return e instanceof ErrorSolicitud ? e.message : defecto;
}

// ───────────── Indicadores ─────────────
const papeles = (e: EntradaCatalogoRecepcion) =>
    e.activa ? (previas.value[e.id]?.documentos ?? 0) : 0;
const activas = computed(() => lista.value.filter((e) => e.activa));
const agregadas = computed(() => lista.value.filter((e) => e.es_personalizada));
const totalPapeles = computed(() =>
    lista.value.reduce((s, e) => s + papeles(e), 0),
);
const totalEventos = computed(() =>
    activas.value.reduce((s, e) => s + (previas.value[e.id]?.eventos ?? 0), 0),
);
const ultimoCambio = computed(() => {
    const fechas = lista.value
        .map((e) => e.actualizado_en)
        .filter((f): f is string => f !== null)
        .sort();

    return fechas.length ? fechas[fechas.length - 1] : null;
});

// ───────────── Agrupado por bloque del corte ─────────────
type Grupo = {
    bloque: string;
    etiqueta: string;
    entradas: EntradaCatalogoRecepcion[];
};

/**
 * Mismo criterio que el backend (CatalogoReglasRecepcion::ordenPorRegla):
 * la entrada del sistema fija la posición de su bloque; un bloque sin regla
 * propia («Otros documentos») va donde está su primera entrada.
 */
const grupos = computed<Grupo[]>(() => {
    const posicion = new Map<string, number>();

    lista.value.forEach((e, i) => {
        if (!e.es_personalizada) {
            posicion.set(e.bloque, i);
        }
    });
    lista.value.forEach((e, i) => {
        if (!posicion.has(e.bloque)) {
            posicion.set(e.bloque, i);
        }
    });

    const mapa = new Map<string, Grupo>();

    for (const e of lista.value) {
        const grupo = mapa.get(e.bloque) ?? {
            bloque: e.bloque,
            etiqueta: e.bloque_etiqueta,
            entradas: [],
        };
        grupo.entradas.push(e);
        mapa.set(e.bloque, grupo);
    }

    for (const g of mapa.values()) {
        g.entradas.sort(
            (a, b) => Number(a.es_personalizada) - Number(b.es_personalizada),
        );
    }

    return [...mapa.values()].sort(
        (a, b) => (posicion.get(a.bloque) ?? 0) - (posicion.get(b.bloque) ?? 0),
    );
});

function totalGrupo(g: Grupo): number {
    return g.entradas.reduce((s, e) => s + papeles(e), 0);
}

// ───────────── Ordenar bloques (arrastrar o flechas) ─────────────
const arrastrando = ref<string | null>(null);

function aplanar(gs: Grupo[]): EntradaCatalogoRecepcion[] {
    return gs.flatMap((g) => g.entradas);
}

function moverGrupo(indice: number, delta: -1 | 1) {
    const copia = [...grupos.value];
    const destino = indice + delta;

    if (destino < 0 || destino >= copia.length) {
        return;
    }

    [copia[indice], copia[destino]] = [copia[destino], copia[indice]];
    lista.value = aplanar(copia);
    void guardarOrden();
}

function moverEnGrupo(
    grupo: Grupo,
    entrada: EntradaCatalogoRecepcion,
    delta: -1 | 1,
) {
    const propias = grupo.entradas.filter((e) => e.es_personalizada);
    const i = propias.findIndex((e) => e.id === entrada.id);
    const destino = i + delta;

    if (i < 0 || destino < 0 || destino >= propias.length) {
        return;
    }

    [propias[i], propias[destino]] = [propias[destino], propias[i]];
    const fijas = grupo.entradas.filter((e) => !e.es_personalizada);
    lista.value = aplanar(
        grupos.value.map((g) =>
            g.bloque === grupo.bloque
                ? { ...g, entradas: [...fijas, ...propias] }
                : g,
        ),
    );
    void guardarOrden();
}

function alIniciarArrastre(bloque: string, evento: DragEvent) {
    arrastrando.value = bloque;
    evento.dataTransfer?.setData('text/plain', bloque);

    if (evento.dataTransfer) {
        evento.dataTransfer.effectAllowed = 'move';
    }
}

function alPasarSobre(bloque: string) {
    const origen = arrastrando.value;

    if (origen === null || origen === bloque) {
        return;
    }

    const copia = [...grupos.value];
    const desde = copia.findIndex((g) => g.bloque === origen);
    const hasta = copia.findIndex((g) => g.bloque === bloque);

    if (desde < 0 || hasta < 0) {
        return;
    }

    const [movido] = copia.splice(desde, 1);
    copia.splice(hasta, 0, movido);
    lista.value = aplanar(copia);
}

function alSoltar() {
    if (arrastrando.value !== null) {
        arrastrando.value = null;
        void guardarOrden();
    }
}

async function guardarOrden() {
    ordenando.value = true;

    try {
        const r = await enviarJson<RespuestaCatalogoRecepcion>(
            'POST',
            rutaReordenar.url(),
            { ids: lista.value.map((e) => e.id) },
        );
        lista.value = r.entradas;
        toast.success('Orden guardado: así se verán los bloques del corte.');
    } catch (e) {
        lista.value = [...props.entradas];
        toast.error(mensajeError(e, 'No se pudo guardar el orden.'));
    } finally {
        ordenando.value = false;
    }
}

// ───────────── Revisar recálculo → aplicar (un solo diálogo) ─────────────
const revision = reactive({
    abierto: false,
    titulo: '',
    descripcion: '',
    cargando: false,
    aplicando: false,
    simulacion: null as SimulacionRecepcion | null,
    error: '',
    verTodas: false,
    aplicar: null as null | (() => Promise<RespuestaCatalogoRecepcion>),
    alTerminar: null as null | (() => void),
});

async function revisar(opciones: {
    titulo: string;
    descripcion: string;
    simular: Record<string, unknown>;
    aplicar: () => Promise<RespuestaCatalogoRecepcion>;
    alTerminar?: () => void;
}) {
    Object.assign(revision, {
        abierto: true,
        titulo: opciones.titulo,
        descripcion: opciones.descripcion,
        cargando: true,
        aplicando: false,
        simulacion: null,
        error: '',
        verTodas: false,
        aplicar: opciones.aplicar,
        alTerminar: opciones.alTerminar ?? null,
    });

    try {
        const r = await enviarJson<{ data: SimulacionRecepcion }>(
            'POST',
            rutaSimular.url(),
            opciones.simular,
        );
        revision.simulacion = r.data;
    } catch (e) {
        revision.error = mensajeError(
            e,
            'No se pudo calcular la vista previa.',
        );

        if (e instanceof ErrorSolicitud) {
            editor.errores = e.errores;
        }
    } finally {
        revision.cargando = false;
    }
}

async function confirmarRevision() {
    if (!revision.aplicar) {
        return;
    }

    revision.aplicando = true;

    try {
        aplicarRespuesta(await revision.aplicar());
        revision.abierto = false;
        revision.alTerminar?.();
    } catch (e) {
        revision.error = mensajeError(e, 'No se pudo aplicar el cambio.');
    } finally {
        revision.aplicando = false;
    }
}

function signo(n: number): string {
    return n > 0 ? `+${n}` : String(n);
}

function claseDiferencia(n: number): string {
    if (n > 0) {
        return 'bg-info-soft text-info';
    }

    return n < 0
        ? 'bg-warning-soft text-warning'
        : 'bg-muted text-muted-foreground';
}

// ───────────── Encender / apagar (con revisión) ─────────────
function alternar(entrada: EntradaCatalogoRecepcion, activa: boolean) {
    if (
        activa &&
        !entrada.es_personalizada &&
        !entrada.documentos.some((d) => d.elegido)
    ) {
        toast.error('Elige al menos un documento antes de encenderla.');
        abrirEditor(entrada);

        return;
    }

    void revisar({
        titulo: `${activa ? 'Encender' : 'Apagar'} «${entrada.nombre}»`,
        descripcion: activa
            ? 'Vuelve a esperarse en los cortes abiertos. Lo que el sistema puso en «No aplica» regresa a pendiente.'
            : 'Deja de esperarse en los cortes abiertos: lo pendiente pasa a «No aplica» y lo ya recibido se conserva.',
        simular: { accion: 'actualizar', entrada_id: entrada.id, activa },
        aplicar: () =>
            enviarJson<RespuestaCatalogoRecepcion>(
                'PATCH',
                rutaActualizar.url(entrada.id),
                { activa },
            ),
    });
}

function eliminar(entrada: EntradaCatalogoRecepcion) {
    void revisar({
        titulo: `Borrar «${entrada.nombre}»`,
        descripcion:
            'Nunca generó un renglón en ningún corte, así que se puede borrar sin perder historial.',
        simular: { accion: 'eliminar', entrada_id: entrada.id },
        aplicar: () =>
            enviarJson<RespuestaCatalogoRecepcion>(
                'DELETE',
                rutaEliminar.url(entrada.id),
            ),
    });
}

// ───────────── Editor (agregar / configurar) ─────────────
const editor = reactive({
    abierto: false,
    entrada: null as EntradaCatalogoRecepcion | null,
    nombre: '',
    descripcion: '',
    modo_calculo: 'por_sucursal' as ModoCalculoRecepcion,
    cantidad: 1,
    regla_base: null as string | null,
    bloque: 'personalizada',
    activa: true,
    documentos: [] as string[],
    copias: {} as Record<string, number>,
    sucursal_ids: [] as number[],
    empresa_ids: [] as number[],
    departamento_ids: [] as number[],
    errores: {} as Record<string, string>,
});
const previa = ref<VistaPreviaRecepcion | null>(null);
const calculandoPrevia = ref(false);

const esNueva = computed(() => editor.entrada === null);
const esAgregada = computed(
    () => esNueva.value || editor.entrada?.es_personalizada === true,
);
const modoActual = computed(() =>
    opciones.value.modos.find((m) => m.value === editor.modo_calculo),
);
const etiquetaCantidad = computed(() => {
    switch (editor.modo_calculo) {
        case 'fijo_mensual':
            return 'Papeles al mes';
        case 'por_sucursal':
            return 'Papeles por sucursal';
        case 'por_colaborador_activo':
            return 'Papeles por colaborador activo';
        default:
            return 'Papeles por evento';
    }
});
const opcionesBloque = computed(() =>
    opciones.value.bloques.map((b) => ({ value: b.value, label: b.label })),
);
const opcionesReglaBase = computed(() =>
    opciones.value.reglas_base.map((r) => ({
        value: r.value,
        label: r.label,
        descripcion: r.descripcion,
    })),
);

function abrirNueva() {
    Object.assign(editor, {
        abierto: true,
        entrada: null,
        nombre: '',
        descripcion: '',
        modo_calculo: 'por_sucursal',
        cantidad: 1,
        regla_base: null,
        bloque: 'personalizada',
        activa: true,
        documentos: [],
        copias: {},
        sucursal_ids: [],
        empresa_ids: [],
        departamento_ids: [],
        errores: {},
    });
    previa.value = null;
}

function abrirEditor(entrada: EntradaCatalogoRecepcion) {
    Object.assign(editor, {
        abierto: true,
        entrada,
        nombre: entrada.nombre,
        descripcion: entrada.descripcion ?? '',
        modo_calculo: entrada.modo_calculo,
        cantidad: entrada.cantidad,
        regla_base: entrada.regla_base,
        bloque: entrada.bloque,
        activa: entrada.activa,
        documentos: entrada.documentos
            .filter((d) => d.elegido)
            .map((d) => d.clave),
        copias: Object.fromEntries(
            entrada.documentos.map((d) => [d.clave, d.copias]),
        ),
        sucursal_ids: [...entrada.sucursal_ids],
        empresa_ids: [...entrada.empresa_ids],
        departamento_ids: [...entrada.departamento_ids],
        errores: {},
    });
    previa.value = previas.value[entrada.id] ?? null;
}

function elegirReglaBase(valor: string | null) {
    editor.regla_base = valor;

    // Por evento: por defecto aparece en el bloque de ese evento.
    if (valor && esNueva.value) {
        editor.bloque = valor;
    }
}

function alternarDocumento(clave: string, elegido: boolean) {
    editor.documentos = elegido
        ? [...new Set([...editor.documentos, clave])]
        : editor.documentos.filter((c) => c !== clave);
}

/** Lo que se manda al guardar / simular según el tipo de entrada. */
function cuerpoEditor(): Record<string, unknown> {
    const comun = {
        nombre: editor.nombre,
        descripcion: editor.descripcion,
        activa: editor.activa,
        sucursal_ids: editor.sucursal_ids,
        empresa_ids: editor.empresa_ids,
    };

    if (!esAgregada.value) {
        return {
            ...comun,
            documentos: editor.documentos,
            copias: Object.fromEntries(
                editor.documentos.map((c) => [
                    c,
                    Math.max(1, Math.round(editor.copias[c] ?? 1)),
                ]),
            ),
        };
    }

    return {
        ...comun,
        modo_calculo: editor.modo_calculo,
        cantidad: Math.max(1, Math.round(editor.cantidad || 1)),
        regla_base:
            editor.modo_calculo === 'por_evento' ? editor.regla_base : null,
        bloque: editor.bloque,
        departamento_ids: modoActual.value?.areas
            ? editor.departamento_ids
            : [],
        ...(modoActual.value?.alcance
            ? {}
            : { sucursal_ids: [], empresa_ids: [] }),
    };
}

const calcularPrevia = useDebounceFn(async () => {
    if (!editor.abierto || !props.puedeConfigurar) {
        return;
    }

    calculandoPrevia.value = true;

    try {
        const cuerpo = { ...cuerpoEditor(), activa: true };
        delete (cuerpo as Record<string, unknown>).nombre;
        delete (cuerpo as Record<string, unknown>).descripcion;
        delete (cuerpo as Record<string, unknown>).bloque;
        const r = await enviarJson<{ data: VistaPreviaRecepcion }>(
            'POST',
            editor.entrada
                ? rutaVistaPrevia.url(editor.entrada.id)
                : rutaVistaPreviaNueva.url(),
            cuerpo,
        );
        previa.value = r.data;
    } catch {
        previa.value = null;
    } finally {
        calculandoPrevia.value = false;
    }
}, 350);

watch(
    () => [
        editor.abierto,
        editor.modo_calculo,
        editor.cantidad,
        editor.regla_base,
        editor.documentos.join('|'),
        JSON.stringify(editor.copias),
        editor.sucursal_ids.join('|'),
        editor.empresa_ids.join('|'),
        editor.departamento_ids.join('|'),
    ],
    () => {
        if (editor.abierto) {
            void calcularPrevia();
        }
    },
);

const sucursalesFiltradas = computed(() =>
    editor.empresa_ids.length
        ? props.sucursales.filter(
              (s) =>
                  s.empresa_id !== null &&
                  editor.empresa_ids.includes(s.empresa_id),
          )
        : props.sucursales,
);

function revisarEditor() {
    editor.errores = {};
    const cuerpo = cuerpoEditor();
    const entrada = editor.entrada;

    void revisar({
        titulo: entrada
            ? `Guardar cambios de «${editor.nombre || entrada.nombre}»`
            : `Agregar «${editor.nombre || 'nueva entrada'}»`,
        descripcion:
            'Así quedaría el No. de papeles de cada corte abierto. Los meses cerrados no cambian.',
        simular: entrada
            ? { accion: 'actualizar', entrada_id: entrada.id, ...cuerpo }
            : { accion: 'crear', ...cuerpo },
        aplicar: () =>
            entrada
                ? enviarJson<RespuestaCatalogoRecepcion>(
                      'PATCH',
                      rutaActualizar.url(entrada.id),
                      cuerpo,
                  )
                : enviarJson<RespuestaCatalogoRecepcion>(
                      'POST',
                      rutaCrear.url(),
                      cuerpo,
                  ),
        alTerminar: () => (editor.abierto = false),
    });
}

// ───────────── Texto de cada tarjeta ─────────────
function chipsDocumentos(e: EntradaCatalogoRecepcion): string[] {
    if (e.es_personalizada) {
        return [];
    }

    return e.documentos
        .filter((d) => d.elegido)
        .map((d) =>
            d.copias > 1 ? `${d.etiqueta} × ${d.copias}` : d.etiqueta,
        );
}

function alcance(e: EntradaCatalogoRecepcion): string {
    if (e.modo_calculo === 'fijo_mensual') {
        return 'General (sin sucursal)';
    }

    const partes: string[] = [];

    if (e.empresa_ids.length) {
        partes.push(
            `${e.empresa_ids.length} empresa${e.empresa_ids.length === 1 ? '' : 's'}`,
        );
    }

    if (e.sucursal_ids.length) {
        partes.push(
            `${e.sucursal_ids.length} sucursal${e.sucursal_ids.length === 1 ? '' : 'es'}`,
        );
    }

    if (e.departamento_ids.length) {
        partes.push(
            `${e.departamento_ids.length} área${e.departamento_ids.length === 1 ? '' : 's'}`,
        );
    }

    return partes.length ? `Solo ${partes.join(', ')}` : 'Todas las sucursales';
}
</script>

<template>
    <Head title="Lo que recibo · Recepción documental" />

    <div class="pagina-ancha flex min-w-0 flex-col gap-5">
        <CrudPageHeader
            titulo="Lo que recibo cada mes"
            :descripcion="`Qué originales deben llegar por paquetería y cuántos papeles. Agrega, quita o cambia lo que se espera; antes de guardar ves cómo cambia el No. de papeles de cada corte abierto. Datos con los eventos reales de ${periodoActual.etiqueta}.`"
            :icono="Settings2"
            detalle
        >
            <Button variant="outline" as-child>
                <Link
                    data-tour="recepcion-catalogo-volver"
                    :href="inicioRecepcion.url()"
                    ><ArrowLeft class="size-4" /> Volver a los cortes</Link
                >
            </Button>
            <Button
                v-if="puedeConfigurar"
                data-testid="catalogo-agregar"
                @click="abrirNueva"
            >
                <PackagePlus class="size-4" /> Agregar lo que debe llegar
            </Button>
        </CrudPageHeader>

        <div
            data-tour="recepcion-catalogo-indicadores"
            class="grid entrada-escalonada grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <KpiRecepcion
                :etiqueta="`No. de papeles en ${periodoActual.etiqueta}`"
                :valor="totalPapeles"
                :icono="FileStack"
                tono="info"
                subtitulo="Con la configuración guardada"
            />
            <KpiRecepcion
                etiqueta="Entradas encendidas"
                :valor="activas.length"
                :icono="ToggleRight"
                tono="success"
                :subtitulo="`de ${lista.length} en el catálogo`"
                :avance="
                    lista.length ? (activas.length * 100) / lista.length : 0
                "
            />
            <KpiRecepcion
                etiqueta="Agregadas por RH"
                :valor="agregadas.length"
                :icono="Sparkles"
                tono="warning"
                subtitulo="Además de las reglas del sistema"
            />
            <KpiRecepcion
                etiqueta="Eventos detectados"
                :valor="totalEventos"
                :icono="CalendarCheck"
                tono="neutro"
                :subtitulo="
                    ultimoCambio
                        ? `Último cambio: ${formatearFechaHora(ultimoCambio)}`
                        : 'Sin cambios todavía'
                "
            />
        </div>

        <section
            data-tour="recepcion-catalogo-entradas"
            class="flex flex-col gap-3"
        >
            <div class="flex flex-wrap items-end justify-between gap-2">
                <div class="min-w-0">
                    <h2 class="text-sm font-semibold">Bloques del corte</h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            puedeConfigurar
                                ? 'Arrastra un bloque (o usa las flechas) para cambiar su orden en el corte y en el PDF.'
                                : 'Solo lectura: para cambiar el catálogo se necesita el permiso «Configurar el catálogo de lo que se recibe».'
                        }}
                    </p>
                </div>
                <span
                    v-if="ordenando"
                    class="flex items-center gap-1.5 text-xs text-muted-foreground"
                    ><Loader2 class="size-3.5 animate-spin" /> Guardando
                    orden…</span
                >
            </div>

            <div v-if="grupos.length" class="flex flex-col gap-3">
                <article
                    v-for="(grupo, gi) in grupos"
                    :key="grupo.bloque"
                    :data-testid="`bloque-${grupo.bloque}`"
                    class="min-w-0 rounded-2xl border bg-card shadow-sm transicion-ui"
                    :class="
                        arrastrando === grupo.bloque
                            ? 'scale-[0.99] opacity-60 ring-2 ring-oro/50'
                            : 'border-border/60'
                    "
                    @dragover.prevent="alPasarSobre(grupo.bloque)"
                    @drop.prevent="alSoltar"
                >
                    <header
                        class="flex flex-wrap items-center gap-2 border-b border-border/50 bg-crema/40 px-3 py-2.5 sm:px-4"
                    >
                        <span
                            v-if="puedeConfigurar"
                            class="hidden cursor-grab text-muted-foreground active:cursor-grabbing sm:block"
                            draggable="true"
                            :aria-label="`Arrastrar el bloque ${grupo.etiqueta}`"
                            @dragstart="alIniciarArrastre(grupo.bloque, $event)"
                            @dragend="alSoltar"
                        >
                            <GripVertical class="size-4" />
                        </span>
                        <h3
                            class="min-w-0 flex-1 truncate text-sm font-semibold tracking-wide uppercase"
                        >
                            {{ grupo.etiqueta }}
                        </h3>
                        <span class="text-xs text-muted-foreground tabular-nums"
                            ><strong class="text-sm text-foreground">{{
                                totalGrupo(grupo)
                            }}</strong>
                            papeles este mes</span
                        >
                        <div v-if="puedeConfigurar" class="flex items-center">
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :disabled="gi === 0 || ordenando"
                                :aria-label="`Subir el bloque ${grupo.etiqueta}`"
                                @click="moverGrupo(gi, -1)"
                                ><ArrowUp class="size-4"
                            /></Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                class="size-8"
                                :disabled="
                                    gi === grupos.length - 1 || ordenando
                                "
                                :aria-label="`Bajar el bloque ${grupo.etiqueta}`"
                                @click="moverGrupo(gi, 1)"
                                ><ArrowDown class="size-4"
                            /></Button>
                        </div>
                    </header>

                    <ul class="divide-y divide-border/50">
                        <li
                            v-for="entrada in grupo.entradas"
                            :key="entrada.id"
                            :data-testid="`entrada-${entrada.clave}`"
                            class="flex flex-col gap-3 p-3 sm:p-4 md:flex-row md:items-center"
                            :class="{ 'bg-crema/30': !entrada.activa }"
                        >
                            <div class="flex min-w-0 flex-1 items-start gap-3">
                                <span
                                    class="flex size-9 shrink-0 items-center justify-center rounded-xl"
                                    :class="
                                        entrada.activa
                                            ? entrada.es_personalizada
                                                ? 'bg-menta text-primary'
                                                : 'bg-salvia text-primary'
                                            : 'bg-muted text-muted-foreground'
                                    "
                                    aria-hidden="true"
                                >
                                    <Sparkles
                                        v-if="entrada.es_personalizada"
                                        class="size-4"
                                    />
                                    <PackageCheck v-else class="size-4" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <span
                                            class="font-semibold break-words"
                                            >{{ entrada.nombre }}</span
                                        >
                                        <Badge
                                            :variant="
                                                entrada.es_personalizada
                                                    ? 'info'
                                                    : 'outline'
                                            "
                                            >{{
                                                entrada.es_personalizada
                                                    ? entrada.modo_etiqueta
                                                    : 'Sistema'
                                            }}</Badge
                                        >
                                        <Badge
                                            :variant="
                                                entrada.activa
                                                    ? 'success'
                                                    : 'secondary'
                                            "
                                            >{{
                                                entrada.activa
                                                    ? 'Encendida'
                                                    : 'Apagada'
                                            }}</Badge
                                        >
                                    </div>
                                    <p
                                        class="mt-0.5 text-xs text-muted-foreground"
                                    >
                                        {{
                                            entrada.descripcion ||
                                            entrada.evento
                                        }}
                                    </p>
                                    <div class="mt-2 flex flex-wrap gap-1.5">
                                        <span
                                            v-for="chip in chipsDocumentos(
                                                entrada,
                                            )"
                                            :key="chip"
                                            class="rounded-full bg-menta px-2 py-0.5 text-xs"
                                            >{{ chip }}</span
                                        >
                                        <span
                                            v-if="
                                                entrada.es_personalizada &&
                                                entrada.descripcion
                                            "
                                            class="rounded-full bg-menta px-2 py-0.5 text-xs"
                                            >{{ entrada.evento }}</span
                                        >
                                        <span
                                            class="rounded-full border border-border/70 px-2 py-0.5 text-xs text-muted-foreground"
                                            >{{ alcance(entrada) }}</span
                                        >
                                    </div>
                                </div>
                            </div>

                            <div
                                class="flex flex-wrap items-center justify-between gap-3 md:flex-nowrap md:justify-end"
                            >
                                <div class="text-left md:text-right">
                                    <span
                                        class="block text-2xl leading-none font-bold tabular-nums"
                                        :class="
                                            entrada.activa
                                                ? 'text-foreground'
                                                : 'text-muted-foreground'
                                        "
                                        ><NumeroAnimado
                                            :valor="papeles(entrada)"
                                    /></span>
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            entrada.activa
                                                ? `papeles · ${previas[entrada.id]?.eventos ?? 0} evento(s)`
                                                : 'no se espera nada'
                                        }}
                                    </span>
                                </div>
                                <div
                                    v-if="puedeConfigurar"
                                    class="flex items-center gap-1"
                                >
                                    <template v-if="entrada.es_personalizada">
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            :disabled="ordenando"
                                            :aria-label="`Subir ${entrada.nombre} dentro del bloque`"
                                            @click="
                                                moverEnGrupo(grupo, entrada, -1)
                                            "
                                            ><ArrowUp class="size-4"
                                        /></Button>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="size-8"
                                            :disabled="ordenando"
                                            :aria-label="`Bajar ${entrada.nombre} dentro del bloque`"
                                            @click="
                                                moverEnGrupo(grupo, entrada, 1)
                                            "
                                            ><ArrowDown class="size-4"
                                        /></Button>
                                        <Button
                                            v-if="entrada.puede_eliminar"
                                            variant="ghost"
                                            size="icon"
                                            class="size-8 text-destructive"
                                            :aria-label="`Borrar ${entrada.nombre}`"
                                            @click="eliminar(entrada)"
                                            ><Trash2 class="size-4"
                                        /></Button>
                                    </template>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :data-testid="`editar-${entrada.clave}`"
                                        @click="abrirEditor(entrada)"
                                    >
                                        <Pencil class="size-4" /> Editar
                                    </Button>
                                    <InterruptorRecepcion
                                        :model-value="entrada.activa"
                                        :disabled="!puedeConfigurar"
                                        :guardando="false"
                                        :etiqueta="`${entrada.activa ? 'Apagar' : 'Encender'} ${entrada.nombre}`"
                                        class="ml-1"
                                        @update:model-value="
                                            alternar(entrada, $event)
                                        "
                                    />
                                </div>
                            </div>
                        </li>
                    </ul>
                </article>
            </div>
            <EmptyState
                v-else
                :icono="PackageCheck"
                titulo="El catálogo está vacío"
                descripcion="Las entradas del sistema se crean solas con los valores por defecto la primera vez que se abre esta pantalla."
            />

            <button
                v-if="puedeConfigurar"
                type="button"
                class="flex tarjeta-interactiva items-center justify-center gap-2 rounded-2xl border border-dashed border-border p-4 text-sm text-muted-foreground"
                @click="abrirNueva"
            >
                <Plus class="size-4" /> Agregar algo que debe llegar cada mes
                (lista firmada, reporte de caja…)
            </button>
        </section>
    </div>

    <!-- ───────────── Agregar / editar ───────────── -->
    <Sheet v-model:open="editor.abierto">
        <SheetContent
            class="flex w-full flex-col gap-0 overflow-y-auto sm:max-w-xl"
        >
            <SheetHeader>
                <SheetTitle>{{
                    esNueva
                        ? 'Agregar lo que debe llegar'
                        : `Editar «${editor.entrada?.nombre}»`
                }}</SheetTitle>
                <SheetDescription>{{
                    esNueva
                        ? 'Un documento que debe llegar cada mes. Elige cómo se cuentan sus papeles.'
                        : editor.entrada?.evento
                }}</SheetDescription>
            </SheetHeader>

            <div class="flex flex-col gap-5 px-4 pb-4">
                <div class="flex flex-col gap-1.5">
                    <Label for="catalogo-nombre">Nombre</Label>
                    <Input
                        id="catalogo-nombre"
                        v-model="editor.nombre"
                        maxlength="160"
                        placeholder="p. ej. Reporte de caja firmado"
                    />
                    <p
                        v-if="editor.errores.nombre"
                        class="text-xs text-destructive"
                    >
                        {{ editor.errores.nombre }}
                    </p>
                </div>

                <div class="flex flex-col gap-1.5">
                    <Label for="catalogo-descripcion"
                        >Nota para el equipo (opcional)</Label
                    >
                    <Textarea
                        id="catalogo-descripcion"
                        v-model="editor.descripcion"
                        maxlength="500"
                        rows="2"
                        placeholder="Qué llega y de dónde, para quien recibe la paquetería."
                    />
                </div>

                <!-- Entrada agregada: cómo se calcula -->
                <template v-if="esAgregada">
                    <fieldset class="flex flex-col gap-2">
                        <legend class="mb-1 text-sm font-medium">
                            ¿Cómo se cuentan los papeles?
                        </legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label
                                v-for="m in opciones.modos"
                                :key="m.value"
                                class="flex fila-interactiva cursor-pointer flex-col gap-0.5 rounded-xl border border-border/60 px-3 py-2.5"
                                :class="{
                                    'border-primary/50 bg-menta/50':
                                        editor.modo_calculo === m.value,
                                }"
                            >
                                <span
                                    class="flex items-center gap-2 text-sm font-medium"
                                >
                                    <input
                                        v-model="editor.modo_calculo"
                                        type="radio"
                                        name="modo"
                                        class="accent-primary"
                                        :value="m.value"
                                    />
                                    {{ m.label }}
                                </span>
                                <span class="text-xs text-muted-foreground">{{
                                    m.descripcion
                                }}</span>
                            </label>
                        </div>
                        <p
                            v-if="editor.errores.modo_calculo"
                            class="text-xs text-destructive"
                        >
                            {{ editor.errores.modo_calculo }}
                        </p>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <Label for="catalogo-cantidad">{{
                                etiquetaCantidad
                            }}</Label>
                            <Input
                                id="catalogo-cantidad"
                                v-model.number="editor.cantidad"
                                type="number"
                                min="1"
                                max="500"
                                inputmode="numeric"
                            />
                            <p
                                v-if="editor.errores.cantidad"
                                class="text-xs text-destructive"
                            >
                                {{ editor.errores.cantidad }}
                            </p>
                        </div>
                        <div class="flex flex-col gap-1.5">
                            <Label>Bloque del corte</Label>
                            <ComboboxBuscable
                                :model-value="editor.bloque"
                                :opciones="opcionesBloque"
                                aria-label="Bloque del corte"
                                @update:model-value="
                                    editor.bloque = String(
                                        $event ?? 'personalizada',
                                    )
                                "
                            />
                        </div>
                    </div>

                    <div
                        v-if="editor.modo_calculo === 'por_evento'"
                        class="flex flex-col gap-1.5"
                    >
                        <Label>¿Por cada evento de…?</Label>
                        <ComboboxBuscable
                            :model-value="editor.regla_base"
                            :opciones="opcionesReglaBase"
                            placeholder="Elige el evento (altas, bajas, nómina…)"
                            aria-label="Evento del sistema"
                            @update:model-value="
                                elegirReglaBase(
                                    $event === null ? null : String($event),
                                )
                            "
                        />
                        <p
                            v-if="editor.errores.regla_base"
                            class="text-xs text-destructive"
                        >
                            {{ editor.errores.regla_base }}
                        </p>
                    </div>

                    <div
                        v-if="modoActual?.areas && departamentos.length"
                        class="flex flex-col gap-1.5"
                    >
                        <Label>Áreas</Label>
                        <SeleccionMultipleBuscable
                            v-model="editor.departamento_ids"
                            :opciones="departamentos"
                            placeholder="Agregar área…"
                            texto-todos="Todas las áreas"
                            nombre-campo="Agregar área"
                        />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :model-value="editor.activa"
                            @update:model-value="
                                editor.activa = $event === true
                            "
                        />
                        Encendida (se espera desde el corte abierto)
                    </label>
                </template>

                <!-- Regla del sistema: documentos y copias -->
                <fieldset v-else class="flex flex-col gap-2">
                    <legend class="mb-1 text-sm font-medium">
                        Documentos que cuentan y copias por evento
                    </legend>
                    <div
                        v-for="doc in editor.entrada?.documentos ?? []"
                        :key="doc.clave"
                        class="flex items-center gap-3 rounded-xl border border-border/60 px-3 py-2 text-sm"
                        :class="{
                            'border-primary/40 bg-menta/50':
                                editor.documentos.includes(doc.clave),
                        }"
                    >
                        <Checkbox
                            :id="`doc-${doc.clave}`"
                            :model-value="editor.documentos.includes(doc.clave)"
                            @update:model-value="
                                alternarDocumento(doc.clave, $event === true)
                            "
                        />
                        <label
                            :for="`doc-${doc.clave}`"
                            class="min-w-0 flex-1 cursor-pointer"
                        >
                            {{ doc.etiqueta }}
                            <span
                                v-if="previa"
                                class="block text-xs text-muted-foreground tabular-nums"
                            >
                                {{
                                    previa.por_documento.find(
                                        (p) => p.clave === doc.clave,
                                    )?.cantidad ?? 0
                                }}
                                este mes
                            </span>
                        </label>
                        <div class="flex shrink-0 items-center gap-1">
                            <span class="text-xs text-muted-foreground">×</span>
                            <Input
                                v-model.number="editor.copias[doc.clave]"
                                type="number"
                                min="1"
                                max="500"
                                inputmode="numeric"
                                class="h-8 w-16"
                                :disabled="
                                    !editor.documentos.includes(doc.clave)
                                "
                                :aria-label="`Copias de ${doc.etiqueta}`"
                            />
                        </div>
                    </div>
                    <p
                        v-if="
                            editor.errores.documentos || editor.errores.copias
                        "
                        class="text-xs text-destructive"
                    >
                        {{ editor.errores.documentos || editor.errores.copias }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        En altas, cada persona espera solo los contratos que su
                        puesto firma (paquete de alta); aquí eliges cuáles de
                        esos llegan en paquetería y cuántas copias.
                    </p>
                </fieldset>

                <div
                    v-if="!esAgregada || modoActual?.alcance"
                    class="grid gap-4 sm:grid-cols-2"
                >
                    <div class="flex flex-col gap-1.5">
                        <Label>Empresas</Label>
                        <SeleccionMultipleBuscable
                            v-model="editor.empresa_ids"
                            :opciones="empresas"
                            placeholder="Agregar empresa…"
                            texto-todos="Todas las empresas"
                            nombre-campo="Agregar empresa"
                        />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label>Sucursales</Label>
                        <SeleccionMultipleBuscable
                            v-model="editor.sucursal_ids"
                            :opciones="sucursalesFiltradas"
                            placeholder="Agregar sucursal…"
                            texto-todos="Todas las sucursales"
                            nombre-campo="Agregar sucursal"
                        />
                    </div>
                </div>

                <section
                    class="rounded-2xl border border-border/60 bg-crema/40 p-4"
                    aria-live="polite"
                >
                    <h3 class="text-sm font-medium">
                        Este mes · {{ periodoActual.etiqueta }}
                    </h3>
                    <CambioSuave>
                        <div
                            v-if="calculandoPrevia && !previa"
                            key="cargando"
                            class="mt-3 flex flex-col gap-2"
                        >
                            <Skeleton class="h-8 w-24 rounded-lg" />
                            <Skeleton class="h-4 w-full rounded" />
                        </div>
                        <div v-else-if="previa" key="previa" class="mt-2">
                            <p class="flex flex-wrap items-baseline gap-2">
                                <span
                                    class="text-3xl font-bold tabular-nums"
                                    :class="{ 'opacity-60': calculandoPrevia }"
                                    ><NumeroAnimado :valor="previa.documentos"
                                /></span>
                                <span class="text-sm text-muted-foreground"
                                    >papel(es) de {{ previa.eventos }}
                                    {{
                                        esAgregada
                                            ? 'sucursal(es) / evento(s)'
                                            : 'evento(s)'
                                    }}</span
                                >
                            </p>
                        </div>
                        <p
                            v-else
                            key="sin"
                            class="mt-2 text-xs text-muted-foreground"
                        >
                            Completa la configuración para ver cuánto se
                            esperaría.
                        </p>
                    </CambioSuave>
                </section>
            </div>

            <SheetFooter
                class="mt-auto flex-row flex-wrap justify-end gap-2 border-t"
            >
                <Button variant="ghost" @click="editor.abierto = false"
                    >Cancelar</Button
                >
                <Button
                    data-testid="catalogo-revisar"
                    :disabled="revision.cargando"
                    @click="revisarEditor"
                >
                    Revisar recálculo <ArrowRight class="size-4" />
                </Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>

    <!-- ───────────── Revisión del recálculo (antes → después) ───────────── -->
    <Dialog v-model:open="revision.abierto">
        <DialogContent
            class="flex max-h-[90dvh] w-[calc(100vw-2rem)] flex-col gap-4 overflow-hidden sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle>{{ revision.titulo }}</DialogTitle>
                <DialogDescription>{{
                    revision.descripcion
                }}</DialogDescription>
            </DialogHeader>

            <div
                class="-mx-1 min-h-0 flex-1 overflow-y-auto px-1"
                data-testid="revision-recalculo"
            >
                <div v-if="revision.cargando" class="flex flex-col gap-2">
                    <Skeleton class="h-16 w-full rounded-xl" />
                    <Skeleton class="h-24 w-full rounded-xl" />
                </div>

                <p
                    v-else-if="revision.error"
                    class="flex items-start gap-2 rounded-xl bg-danger-soft p-3 text-sm text-destructive"
                >
                    <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                    {{ revision.error }}
                </p>

                <div
                    v-else-if="revision.simulacion"
                    class="flex flex-col gap-4"
                >
                    <div
                        class="grid grid-cols-3 gap-2 rounded-2xl bg-crema/50 p-3 text-center"
                    >
                        <div>
                            <p class="text-xs text-muted-foreground">Hoy</p>
                            <p class="text-2xl font-bold tabular-nums">
                                {{ revision.simulacion.total.antes }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">Después</p>
                            <p class="text-2xl font-bold tabular-nums">
                                {{ revision.simulacion.total.despues }}
                            </p>
                        </div>
                        <div>
                            <p class="text-xs text-muted-foreground">
                                Diferencia
                            </p>
                            <p class="mt-1">
                                <span
                                    class="rounded-full px-2 py-0.5 text-sm font-semibold tabular-nums"
                                    :class="
                                        claseDiferencia(
                                            revision.simulacion.total
                                                .diferencia,
                                        )
                                    "
                                    >{{
                                        signo(
                                            revision.simulacion.total
                                                .diferencia,
                                        )
                                    }}</span
                                >
                            </p>
                        </div>
                        <p class="col-span-3 text-xs text-muted-foreground">
                            No. de papeles en
                            {{ revision.simulacion.cortes.length }} corte(s)
                            abierto(s).
                            <template
                                v-if="revision.simulacion.cerrados_intactos"
                            >
                                {{
                                    revision.simulacion.cerrados_intactos
                                }}
                                corte(s) cerrado(s) no cambian.
                            </template>
                        </p>
                    </div>

                    <section
                        v-for="corte in revision.simulacion.cortes"
                        :key="corte.periodo"
                        class="rounded-2xl border border-border/60"
                    >
                        <header
                            class="flex flex-wrap items-center gap-2 border-b border-border/50 px-3 py-2"
                        >
                            <h4 class="min-w-0 flex-1 text-sm font-semibold">
                                {{ corte.etiqueta }}
                                <span class="font-normal text-muted-foreground"
                                    >· {{ corte.estado }}</span
                                >
                            </h4>
                            <span class="text-sm tabular-nums">
                                {{ corte.antes }} →
                                <strong>{{ corte.despues }}</strong>
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-semibold tabular-nums"
                                :class="claseDiferencia(corte.diferencia)"
                                >{{ signo(corte.diferencia) }}</span
                            >
                        </header>
                        <div class="px-3 py-2">
                            <p
                                v-if="corte.sin_cambio !== corte.antes"
                                class="mb-2 text-xs text-muted-foreground"
                            >
                                {{
                                    signo(corte.sin_cambio - corte.antes)
                                }}
                                vienen de eventos nuevos desde el último
                                cálculo; el cambio en sí aporta
                                {{ signo(corte.efecto_del_cambio) }}.
                            </p>
                            <ul
                                class="flex flex-col divide-y divide-border/40 text-sm"
                            >
                                <li
                                    v-for="s in revision.verTodas
                                        ? corte.sucursales
                                        : corte.sucursales.filter(
                                              (x) => x.despues !== x.antes,
                                          )"
                                    :key="`${corte.periodo}-${s.sucursal_id ?? 'sin'}`"
                                    class="flex items-center gap-2 py-1.5"
                                >
                                    <span class="min-w-0 flex-1 truncate">{{
                                        s.nombre
                                    }}</span>
                                    <span
                                        class="shrink-0 text-muted-foreground tabular-nums"
                                        >{{ s.antes }} →</span
                                    >
                                    <span
                                        class="w-10 shrink-0 text-right font-semibold tabular-nums"
                                        >{{ s.despues }}</span
                                    >
                                    <span
                                        class="w-12 shrink-0 rounded-full px-1.5 text-center text-xs tabular-nums"
                                        :class="claseDiferencia(s.diferencia)"
                                        >{{ signo(s.diferencia) }}</span
                                    >
                                </li>
                                <li
                                    v-if="
                                        !revision.verTodas &&
                                        !corte.sucursales.some(
                                            (x) => x.despues !== x.antes,
                                        )
                                    "
                                    class="py-1.5 text-xs text-muted-foreground"
                                >
                                    Ninguna sucursal cambia en este corte.
                                </li>
                            </ul>
                            <ul
                                v-if="corte.conflictos.length"
                                class="mt-2 flex flex-col gap-1.5"
                            >
                                <li
                                    v-for="c in corte.conflictos"
                                    :key="`${c.renglon_id}-${c.tipo}`"
                                    class="flex items-start gap-2 rounded-lg bg-warning-soft p-2 text-xs"
                                >
                                    <AlertTriangle
                                        class="mt-0.5 size-3.5 shrink-0 text-warning"
                                    />
                                    <span
                                        ><strong>{{ c.documento }}</strong> ·
                                        {{ c.persona ?? c.sucursal }}:
                                        {{ c.mensaje }}</span
                                    >
                                </li>
                            </ul>
                        </div>
                    </section>

                    <button
                        type="button"
                        class="self-start text-xs text-primary underline-offset-2 hover:underline"
                        @click="revision.verTodas = !revision.verTodas"
                    >
                        {{
                            revision.verTodas
                                ? 'Ver solo las sucursales que cambian'
                                : 'Ver todas las sucursales'
                        }}
                    </button>
                </div>
            </div>

            <DialogFooter class="flex-row flex-wrap justify-end gap-2">
                <Button variant="ghost" @click="revision.abierto = false"
                    >Volver</Button
                >
                <Button
                    data-testid="revision-aplicar"
                    :disabled="
                        revision.cargando ||
                        revision.aplicando ||
                        !revision.simulacion
                    "
                    @click="confirmarRevision"
                >
                    <Loader2
                        v-if="revision.aplicando"
                        class="size-4 animate-spin"
                    />
                    Aplicar y recalcular
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
