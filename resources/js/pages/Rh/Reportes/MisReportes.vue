<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Copy,
    Eye,
    FileChartColumn,
    FileSpreadsheet,
    FileText,
    Play,
    Plus,
    RefreshCw,
    Save,
    Trash2,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import PanelColumnasArrastre from '@/components/reportes/PanelColumnasArrastre.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    enviarJson,
    ErrorSolicitud,
    leerCookie,
    vistaPreviaPost,
} from '@/lib/http';
import { dashboard } from '@/routes';
import { index as indexReportes } from '@/routes/reportes';
import {
    destroy,
    duplicar,
    ejecutar,
    exportar,
    index,
    store,
    update,
    vistaPrevia,
    vistaPreviaPdf,
} from '@/routes/rh/reportes/mis-reportes';
import type {
    CampoReporte,
    ColorReporte,
    DefinicionReporte,
    DisenoReporte,
    FiltroReporte,
    FuenteReporte,
    OrdenReporte,
    ReporteGuardadoDetalle,
    ReporteGuardadoResumen,
    VistaPreviaReporte,
} from '@/types/misReportes';

const props = defineProps<{
    fuentes: FuenteReporte[];
    guardados: ReporteGuardadoResumen[];
    reporte: ReporteGuardadoDetalle | null;
    disenoInicial: DisenoReporte;
    colores: ColorReporte[];
    puedeExportar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Reportes', href: indexReportes() },
            { title: 'Mis reportes', href: '' },
        ],
    },
});

// Las props de Inertia son proxies reactivos: structuredClone los rechaza.
const clonar = <T,>(valor: T): T => JSON.parse(JSON.stringify(valor)) as T;

const fuente = ref(props.reporte?.fuente ?? props.fuentes[0]?.clave ?? '');
const nombre = ref(props.reporte?.nombre ?? '');
const columnas = ref<CampoReporte[]>([]);
const filtros = ref<FiltroReporte[]>([]);
const orden = ref<OrdenReporte[]>([]);
const diseno = ref<DisenoReporte>(clonar(props.disenoInicial));
const pestana = ref<'tabla' | 'pdf'>('tabla');
const vista = ref<VistaPreviaReporte | null>(null);
const pdfUrl = ref<string | null>(null);
const cargando = ref(false);
const guardando = ref(false);
const error = ref<string | null>(null);

const camposFuente = computed<CampoReporte[]>(
    () => props.fuentes.find((f) => f.clave === fuente.value)?.campos ?? [],
);

function campoDe(clave: string): CampoReporte | undefined {
    return camposFuente.value.find((c) => c.clave === clave);
}

/** Carga el reporte elegido; un campo que ya no se puede usar se descarta. */
function cargar(reporte: ReporteGuardadoDetalle | null) {
    nombre.value = reporte?.nombre ?? '';
    const definicion = reporte?.definicion;
    fuente.value = reporte?.fuente ?? fuente.value;
    columnas.value = (definicion?.columnas ?? [])
        .map((c) => campoDe(c))
        .filter((c): c is CampoReporte => c !== undefined);
    filtros.value = (definicion?.filtros ?? []).filter((f) => campoDe(f.campo));
    orden.value = (definicion?.orden ?? []).filter((o) => campoDe(o.campo));
    diseno.value = {
        ...clonar(props.disenoInicial),
        ...clonar(definicion?.diseno ?? {}),
    };
    vista.value = null;
    limpiarPdf();
}

function cambiarFuente(valor: string) {
    fuente.value = valor;
    columnas.value = [];
    filtros.value = [];
    orden.value = [];
    diseno.value.grafica = { ...props.disenoInicial.grafica };
    vista.value = null;
    limpiarPdf();
}

watch(() => props.reporte, cargar, { immediate: true });

const OPERADORES: Record<string, { valor: string; etiqueta: string }[]> = {
    texto: [
        { valor: 'es', etiqueta: 'es' },
        { valor: 'no_es', etiqueta: 'no es' },
        { valor: 'contiene', etiqueta: 'contiene' },
        { valor: 'vacio', etiqueta: 'está vacío' },
        { valor: 'no_vacio', etiqueta: 'tiene dato' },
    ],
    numero: [
        { valor: 'igual', etiqueta: 'es igual a' },
        { valor: 'mayor', etiqueta: 'mayor que' },
        { valor: 'menor', etiqueta: 'menor que' },
        { valor: 'entre', etiqueta: 'entre' },
        { valor: 'vacio', etiqueta: 'está vacío' },
        { valor: 'no_vacio', etiqueta: 'tiene dato' },
    ],
    fecha: [
        { valor: 'entre', etiqueta: 'entre' },
        { valor: 'desde', etiqueta: 'desde' },
        { valor: 'hasta', etiqueta: 'hasta' },
        { valor: 'vacio', etiqueta: 'está vacío' },
        { valor: 'no_vacio', etiqueta: 'tiene dato' },
    ],
};
OPERADORES.moneda = OPERADORES.numero;

function operadoresDe(campo: string) {
    return OPERADORES[campoDe(campo)?.tipo ?? 'texto'];
}

function agregarFiltro() {
    const campo = camposFuente.value[0];

    if (campo) {
        filtros.value.push({
            campo: campo.clave,
            operador: operadoresDe(campo.clave)[0].valor,
            valor: null,
        });
    }
}

function cambiarCampoFiltro(filtro: FiltroReporte, campo: string) {
    filtro.campo = campo;
    filtro.operador = operadoresDe(campo)[0].valor;
    filtro.valor = null;
}

function cambiarOperador(filtro: FiltroReporte, operador: string) {
    filtro.operador = operador;
    filtro.valor = operador === 'entre' ? ['', ''] : null;
}

const opcionesCampos = computed(() =>
    camposFuente.value.map((c) => ({
        value: c.clave,
        label: c.etiqueta,
        grupo: c.categoria,
    })),
);
const opcionesAgrupar = computed(() =>
    camposFuente.value
        .filter((c) => c.tipo === 'texto' || c.tipo === 'fecha')
        .map((c) => ({
            value: c.clave,
            label: c.etiqueta,
            grupo: c.categoria,
        })),
);
const opcionesValorGrafica = computed(() => [
    { value: 'conteo', label: 'Número de registros' },
    ...camposFuente.value
        .filter((c) => c.tipo === 'numero' || c.tipo === 'moneda')
        .map((c) => ({ value: c.clave, label: `Suma de ${c.etiqueta}` })),
]);

const definicion = computed<DefinicionReporte>(() => ({
    fuente: fuente.value,
    columnas: columnas.value.map((c) => c.clave),
    filtros: filtros.value,
    orden: orden.value,
    diseno: diseno.value,
}));

function limpiarPdf() {
    if (pdfUrl.value) {
        URL.revokeObjectURL(pdfUrl.value);
        pdfUrl.value = null;
    }
}

onBeforeUnmount(limpiarPdf);

function mensajeDe(e: unknown): string {
    return e instanceof ErrorSolicitud
        ? e.message
        : 'No se pudo completar la acción. Intenta de nuevo.';
}

async function actualizarVista() {
    cargando.value = true;
    error.value = null;

    try {
        if (pestana.value === 'pdf') {
            const url = await vistaPreviaPost(vistaPreviaPdf.url(), {
                definicion: definicion.value,
            });
            limpiarPdf();
            pdfUrl.value = url;
        } else {
            vista.value = await enviarJson<VistaPreviaReporte>(
                'POST',
                vistaPrevia.url(),
                { definicion: definicion.value },
            );
        }
    } catch (e) {
        error.value = mensajeDe(e);
    } finally {
        cargando.value = false;
    }
}

function verPestana(destino: 'tabla' | 'pdf') {
    pestana.value = destino;
    actualizarVista();
}

function formatear(valor: string | number | null, tipo: string): string {
    if (valor === null || valor === '') {
        return '—';
    }

    if (typeof valor === 'string' && tipo === 'fecha') {
        const [a, m, d] = valor.split('-');

        return `${d}/${m}/${a}`;
    }

    if (tipo === 'moneda' && typeof valor === 'number') {
        return valor.toLocaleString('es-MX', {
            style: 'currency',
            currency: 'MXN',
        });
    }

    return String(valor);
}

function guardar() {
    guardando.value = true;
    error.value = null;
    const opciones = {
        preserveScroll: true,
        onError: (errores: Record<string, string>) => {
            error.value = Object.values(errores)[0] ?? 'No se pudo guardar.';
        },
        onFinish: () => {
            guardando.value = false;
        },
    };
    const datos = { nombre: nombre.value, definicion: definicion.value };

    if (props.reporte) {
        router.put(update.url(props.reporte.id), datos, opciones);
    } else {
        router.post(store.url(), datos, opciones);
    }
}

function eliminar(id: number, titulo: string) {
    if (window.confirm(`¿Eliminar el reporte «${titulo}»?`)) {
        router.delete(destroy.url(id));
    }
}

const exportando = ref(false);

/** Descarga lo que está en pantalla, sin necesidad de guardarlo antes. */
async function descargar(formato: 'excel' | 'pdf') {
    exportando.value = true;
    error.value = null;

    try {
        const respuesta = await fetch(exportar.url(), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json, application/pdf, application/octet-stream',
                'X-XSRF-TOKEN': leerCookie('XSRF-TOKEN') ?? '',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ formato, definicion: definicion.value }),
        });

        if (!respuesta.ok) {
            const datos = (await respuesta.json().catch(() => null)) as {
                message?: string;
                errors?: Record<string, string[]>;
            } | null;

            error.value =
                (datos?.errors && Object.values(datos.errors)[0]?.[0]) ??
                datos?.message ??
                'No se pudo generar el archivo.';

            return;
        }

        const enlace = document.createElement('a');
        enlace.href = URL.createObjectURL(await respuesta.blob());
        enlace.download = `${(diseno.value.titulo || nombre.value || 'reporte').trim()}.${formato === 'pdf' ? 'pdf' : 'xlsx'}`;
        enlace.click();
        URL.revokeObjectURL(enlace.href);
    } finally {
        exportando.value = false;
    }
}

const hexColor = (clave: string) =>
    props.colores.find((c) => c.clave === clave)?.hex;
</script>

<template>
    <Head title="Mis reportes" />

    <div class="pagina-ancha flex flex-col gap-4">
        <CrudPageHeader
            titulo="Mis reportes"
            descripcion="Arma tu reporte, elige su diseño y guárdalo para volver a sacarlo con datos actualizados."
            :icono="FileChartColumn"
        >
            <Button as-child variant="outline" size="sm">
                <Link :href="index.url()">
                    <Plus class="size-4" /> Reporte nuevo
                </Link>
            </Button>
        </CrudPageHeader>

        <section
            v-if="guardados.length"
            class="rounded-xl border border-border/70 bg-salvia/40 p-3"
            aria-label="Reportes guardados"
        >
            <p class="mb-2 text-sm font-semibold">Mis reportes guardados</p>
            <ul class="flex flex-wrap gap-2">
                <li
                    v-for="g in guardados"
                    :key="g.id"
                    class="flex items-center gap-1 rounded-full border border-border/70 bg-card py-0.5 pr-1 pl-3 text-xs"
                    :class="
                        reporte?.id === g.id ? 'ring-2 ring-primary/40' : ''
                    "
                >
                    <Link
                        :href="index.url({ query: { reporte: g.id } })"
                        class="font-medium hover:underline"
                    >
                        {{ g.nombre }}
                    </Link>
                    <template v-if="puedeExportar">
                        <a
                            :href="ejecutar.url([g.id, 'excel'])"
                            class="rounded p-1 hover:bg-muted"
                            :aria-label="`Generar Excel de ${g.nombre}`"
                            title="Generar Excel"
                        >
                            <FileSpreadsheet class="size-3.5" />
                        </a>
                        <a
                            :href="ejecutar.url([g.id, 'pdf'])"
                            class="rounded p-1 hover:bg-muted"
                            :aria-label="`Generar PDF de ${g.nombre}`"
                            title="Generar PDF"
                        >
                            <Play class="size-3.5" />
                        </a>
                    </template>
                    <button
                        type="button"
                        class="rounded p-1 hover:bg-muted"
                        :aria-label="`Duplicar ${g.nombre}`"
                        title="Duplicar"
                        @click="router.post(duplicar.url(g.id))"
                    >
                        <Copy class="size-3.5" />
                    </button>
                    <button
                        type="button"
                        class="rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                        :aria-label="`Eliminar ${g.nombre}`"
                        title="Eliminar"
                        @click="eliminar(g.id, g.nombre)"
                    >
                        <Trash2 class="size-3.5" />
                    </button>
                </li>
            </ul>
        </section>

        <div
            v-if="error"
            role="alert"
            class="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive"
        >
            {{ error }}
        </div>

        <div
            class="grid items-start gap-4 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]"
        >
            <div class="flex min-w-0 flex-col gap-4">
                <section class="rounded-xl border border-border/70 bg-card p-4">
                    <h2 class="mb-2 text-sm font-semibold">
                        1 · ¿De dónde salen los datos?
                    </h2>
                    <div
                        class="grid gap-1 rounded-lg bg-muted/50 p-1 sm:auto-cols-fr sm:grid-flow-col"
                        role="radiogroup"
                        aria-label="Fuente de datos"
                    >
                        <button
                            v-for="f in fuentes"
                            :key="f.clave"
                            type="button"
                            role="radio"
                            :aria-checked="fuente === f.clave"
                            class="rounded-md px-3 py-1.5 text-sm transition-colors"
                            :class="
                                fuente === f.clave
                                    ? 'bg-primary font-medium text-primary-foreground'
                                    : 'hover:bg-card'
                            "
                            @click="cambiarFuente(f.clave)"
                        >
                            {{ f.etiqueta }}
                        </button>
                    </div>
                </section>

                <section>
                    <h2 class="mb-2 text-sm font-semibold">
                        2 · Elige y ordena las columnas
                    </h2>
                    <PanelColumnasArrastre
                        v-model="columnas"
                        :disponibles="camposFuente"
                    />
                    <p class="mt-1.5 text-xs text-muted-foreground">
                        Los datos con candado solo aparecen para usuarios con
                        ese permiso.
                    </p>
                </section>

                <section class="rounded-xl border border-border/70 bg-card p-4">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold">3 · Filtra</h2>
                        <Button
                            variant="ghost"
                            size="sm"
                            type="button"
                            @click="agregarFiltro"
                        >
                            <Plus class="size-4" /> Agregar filtro
                        </Button>
                    </div>
                    <p
                        v-if="!filtros.length"
                        class="text-xs text-muted-foreground"
                    >
                        Sin filtros: el reporte trae todo lo que puedes ver.
                    </p>
                    <div
                        v-for="(filtro, i) in filtros"
                        :key="i"
                        class="mb-2 grid items-center gap-2 sm:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1.5fr)_auto]"
                    >
                        <SelectSimple
                            :model-value="filtro.campo"
                            :opciones="opcionesCampos"
                            class="w-full"
                            @update:model-value="
                                (v) => cambiarCampoFiltro(filtro, String(v))
                            "
                        />
                        <SelectSimple
                            :model-value="filtro.operador"
                            :opciones="
                                operadoresDe(filtro.campo).map((o) => ({
                                    value: o.valor,
                                    label: o.etiqueta,
                                }))
                            "
                            class="w-full"
                            @update:model-value="
                                (v) => cambiarOperador(filtro, String(v))
                            "
                        />
                        <div
                            v-if="filtro.operador === 'entre'"
                            class="grid grid-cols-2 gap-1"
                        >
                            <Input
                                v-for="n in [0, 1]"
                                :key="n"
                                :type="
                                    campoDe(filtro.campo)?.tipo === 'fecha'
                                        ? 'date'
                                        : 'number'
                                "
                                :model-value="
                                    (filtro.valor as string[])?.[n] ?? ''
                                "
                                :aria-label="n === 0 ? 'Desde' : 'Hasta'"
                                @update:model-value="
                                    (v) => {
                                        const r = [
                                            ...((filtro.valor as string[]) ?? [
                                                '',
                                                '',
                                            ]),
                                        ];
                                        r[n] = String(v);
                                        filtro.valor = r;
                                    }
                                "
                            />
                        </div>
                        <Input
                            v-else-if="
                                !['vacio', 'no_vacio'].includes(filtro.operador)
                            "
                            :type="
                                campoDe(filtro.campo)?.tipo === 'fecha'
                                    ? 'date'
                                    : campoDe(filtro.campo)?.tipo === 'texto'
                                      ? 'text'
                                      : 'number'
                            "
                            :model-value="(filtro.valor as string) ?? ''"
                            aria-label="Valor del filtro"
                            @update:model-value="
                                (v) => (filtro.valor = String(v))
                            "
                        />
                        <span v-else></span>
                        <button
                            type="button"
                            class="justify-self-end rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            aria-label="Quitar filtro"
                            @click="filtros.splice(i, 1)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                </section>

                <section class="rounded-xl border border-border/70 bg-card p-4">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-semibold">4 · Ordena</h2>
                        <Button
                            v-if="orden.length < 3 && camposFuente.length"
                            variant="ghost"
                            size="sm"
                            type="button"
                            @click="
                                orden.push({
                                    campo: camposFuente[0].clave,
                                    direccion: 'asc',
                                })
                            "
                        >
                            <Plus class="size-4" /> Agregar orden
                        </Button>
                    </div>
                    <div
                        v-for="(criterio, i) in orden"
                        :key="i"
                        class="mb-2 grid items-center gap-2 sm:grid-cols-[minmax(0,2fr)_minmax(0,1fr)_auto]"
                    >
                        <SelectSimple
                            v-model="criterio.campo"
                            :opciones="opcionesCampos"
                            class="w-full"
                        />
                        <SelectSimple
                            v-model="criterio.direccion"
                            :opciones="[
                                { value: 'asc', label: 'Ascendente (A–Z)' },
                                { value: 'desc', label: 'Descendente (Z–A)' },
                            ]"
                            class="w-full"
                        />
                        <button
                            type="button"
                            class="justify-self-end rounded p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive"
                            aria-label="Quitar orden"
                            @click="orden.splice(i, 1)"
                        >
                            <X class="size-4" />
                        </button>
                    </div>
                </section>

                <section class="rounded-xl border border-border/70 bg-card p-4">
                    <h2 class="mb-3 text-sm font-semibold">
                        5 · Diseño del PDF y del Excel
                    </h2>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="grid gap-1 text-xs text-muted-foreground">
                            Título
                            <Input
                                v-model="diseno.titulo"
                                maxlength="150"
                                placeholder="Título del reporte"
                            />
                        </label>
                        <label class="grid gap-1 text-xs text-muted-foreground">
                            Subtítulo
                            <Input
                                v-model="diseno.subtitulo"
                                maxlength="200"
                                placeholder="Opcional"
                            />
                        </label>
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Orientación
                            <SelectSimple
                                v-model="diseno.orientacion"
                                :opciones="[
                                    { value: 'landscape', label: 'Horizontal' },
                                    { value: 'portrait', label: 'Vertical' },
                                ]"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Tamaño
                            <SelectSimple
                                v-model="diseno.tamano"
                                :opciones="[
                                    { value: 'letter', label: 'Carta' },
                                    { value: 'a4', label: 'A4' },
                                    { value: 'legal', label: 'Oficio' },
                                ]"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Ajuste de columnas
                            <SelectSimple
                                v-model="diseno.ajuste"
                                :opciones="[
                                    { value: 'compacto', label: 'Compacto' },
                                    { value: 'normal', label: 'Normal' },
                                    { value: 'amplio', label: 'Amplio' },
                                ]"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Color de acento
                            <div class="flex flex-wrap gap-2 pt-1">
                                <button
                                    v-for="c in colores"
                                    :key="c.clave"
                                    type="button"
                                    class="size-7 rounded-full ring-offset-2 transition-shadow"
                                    :class="
                                        diseno.color === c.clave
                                            ? 'ring-2 ring-foreground/60'
                                            : ''
                                    "
                                    :style="{
                                        backgroundColor: hexColor(c.clave),
                                    }"
                                    :aria-label="c.etiqueta"
                                    :aria-pressed="diseno.color === c.clave"
                                    :title="c.etiqueta"
                                    @click="diseno.color = c.clave"
                                ></button>
                            </div>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap gap-x-5 gap-y-2 text-sm">
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="diseno.encabezado"
                                type="checkbox"
                            />
                            Encabezado Mr. Lana
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="diseno.mostrar_filtros"
                                type="checkbox"
                            />
                            Mostrar filtros aplicados
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input v-model="diseno.totales" type="checkbox" />
                            Fila de totales
                        </label>
                        <label class="inline-flex items-center gap-2">
                            <input
                                v-model="diseno.grafica.activa"
                                type="checkbox"
                                data-testid="grafica-activa"
                            />
                            Incluir gráfica
                        </label>
                    </div>

                    <div
                        v-if="diseno.grafica.activa"
                        class="mt-3 grid gap-3 rounded-lg bg-crema/40 p-3 sm:grid-cols-3"
                    >
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Tipo de gráfica
                            <SelectSimple
                                v-model="diseno.grafica.tipo"
                                :opciones="[
                                    { value: 'barras', label: 'Barras' },
                                    { value: 'pastel', label: 'Pastel' },
                                    { value: 'lineas', label: 'Líneas' },
                                ]"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Agrupar por
                            <SelectSimple
                                v-model="diseno.grafica.campo"
                                :opciones="opcionesAgrupar"
                                placeholder="Elige un campo"
                                class="w-full"
                            />
                        </div>
                        <div class="grid gap-1 text-xs text-muted-foreground">
                            Valor
                            <SelectSimple
                                v-model="diseno.grafica.valor"
                                :opciones="opcionesValorGrafica"
                                class="w-full"
                            />
                        </div>
                    </div>
                </section>
            </div>

            <aside
                class="flex min-w-0 flex-col gap-3 rounded-xl border border-border/70 bg-card p-4 xl:sticky xl:top-4"
            >
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-sm font-semibold">
                        6 · Vista previa
                        <span
                            v-if="pestana === 'tabla' && vista"
                            class="font-normal text-muted-foreground"
                        >
                            · {{ vista.total }} resultado(s)
                        </span>
                    </h2>
                    <div class="flex items-center gap-1">
                        <div class="flex rounded-lg bg-muted/50 p-0.5 text-xs">
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1"
                                :class="
                                    pestana === 'tabla'
                                        ? 'bg-card font-medium shadow-sm'
                                        : ''
                                "
                                @click="verPestana('tabla')"
                            >
                                Tabla
                            </button>
                            <button
                                type="button"
                                class="rounded-md px-2.5 py-1"
                                :class="
                                    pestana === 'pdf'
                                        ? 'bg-card font-medium shadow-sm'
                                        : ''
                                "
                                @click="verPestana('pdf')"
                            >
                                PDF
                            </button>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            type="button"
                            :disabled="cargando || !columnas.length"
                            @click="actualizarVista"
                        >
                            <RefreshCw
                                class="size-4"
                                :class="cargando ? 'animate-spin' : ''"
                            />
                            Actualizar
                        </Button>
                    </div>
                </div>

                <template v-if="pestana === 'pdf'">
                    <iframe
                        v-if="pdfUrl"
                        :src="pdfUrl"
                        title="Vista previa del PDF"
                        class="h-[32rem] w-full rounded-lg border border-border/60"
                    ></iframe>
                    <p
                        v-else
                        class="py-12 text-center text-sm text-muted-foreground"
                    >
                        <Eye class="mx-auto mb-2 size-5" />
                        {{
                            cargando
                                ? 'Generando el PDF…'
                                : 'Agrega columnas y pulsa Actualizar para ver el PDF.'
                        }}
                    </p>
                </template>

                <template v-else>
                    <div
                        v-for="aviso in vista?.avisos ?? []"
                        :key="aviso"
                        class="rounded-lg bg-warning-soft px-3 py-2 text-xs text-warning"
                    >
                        {{ aviso }}
                    </div>
                    <p
                        v-if="!vista"
                        class="py-12 text-center text-sm text-muted-foreground"
                    >
                        <Eye class="mx-auto mb-2 size-5" />
                        Agrega columnas y pulsa Actualizar para ver el
                        resultado.
                    </p>
                    <div
                        v-else
                        class="max-h-[28rem] overflow-auto rounded-lg border border-border/60"
                    >
                        <table class="w-full text-xs">
                            <thead class="sticky top-0 bg-crema">
                                <tr>
                                    <th
                                        v-for="c in vista.columnas"
                                        :key="c.clave"
                                        class="px-3 py-2 text-left font-semibold whitespace-nowrap"
                                    >
                                        {{ c.etiqueta }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="(fila, i) in vista.filas"
                                    :key="i"
                                    class="border-t border-border/40"
                                >
                                    <td
                                        v-for="(valor, j) in fila"
                                        :key="j"
                                        class="px-3 py-1.5 whitespace-nowrap"
                                    >
                                        {{
                                            formatear(
                                                valor,
                                                vista.columnas[j]?.tipo ??
                                                    'texto',
                                            )
                                        }}
                                    </td>
                                </tr>
                                <tr v-if="!vista.filas.length">
                                    <td
                                        :colspan="vista.columnas.length"
                                        class="px-3 py-6 text-center text-muted-foreground"
                                    >
                                        No hay información con estos filtros.
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p
                        v-if="vista && vista.total > vista.limite"
                        class="text-xs text-muted-foreground"
                    >
                        Se muestran los primeros {{ vista.limite }} de
                        {{ vista.total }}; el archivo trae todos.
                    </p>
                </template>

                <div class="grid gap-2 border-t border-border/60 pt-3">
                    <label class="grid gap-1 text-xs text-muted-foreground">
                        Nombre del reporte
                        <Input
                            v-model="nombre"
                            maxlength="120"
                            placeholder="Ej. Ingresos 2026 Cuernavaca y Tula"
                        />
                    </label>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            :disabled="
                                guardando || !columnas.length || !nombre.trim()
                            "
                            @click="guardar"
                        >
                            <Save class="size-4" />
                            {{
                                reporte
                                    ? 'Guardar cambios'
                                    : 'Guardar en Mis reportes'
                            }}
                        </Button>
                        <template v-if="puedeExportar">
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="exportando || !columnas.length"
                                @click="descargar('excel')"
                            >
                                <FileSpreadsheet class="size-4" /> Excel
                            </Button>
                            <Button
                                type="button"
                                variant="outline"
                                :disabled="exportando || !columnas.length"
                                @click="descargar('pdf')"
                            >
                                <FileText class="size-4" /> PDF
                            </Button>
                        </template>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>
