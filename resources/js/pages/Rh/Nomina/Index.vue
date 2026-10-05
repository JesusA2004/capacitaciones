<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CalendarClock,
    Download,
    FileStack,
    FileText,
    Layers,
    Pencil,
    Plus,
    Printer,
    ReceiptText,
    Send,
    Trash2,
    Wallet,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import Casilla from '@/components/Common/Casilla.vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import MetricCard from '@/components/Common/MetricCard.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import CrudPageHeader from '@/components/DataTable/CrudPageHeader.vue';
import InputError from '@/components/InputError.vue';
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
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { dashboard } from '@/routes';
import { descargar, emitir, index, masivo, preparar } from '@/routes/rh/nomina';
import {
    emitir as emitirRecibo,
    update as actualizarRecibo,
} from '@/routes/rh/nomina/recibos';
import type { RespuestaPaginada } from '@/types';

/**
 * Recibos de nómina quincenales (docs/NOMINA_QUINCENAL.md): el sistema los
 * prepara como borrador antes del pago y los emite solo en la fecha de
 * pago. Aquí RH revisa la quincena, ajusta uno por uno o en bloque, emite
 * y descarga todos para entregarlos.
 */
type Concepto = {
    tipo: 'percepcion' | 'deduccion';
    concepto: string;
    cantidad?: string | number;
    importe: string | number;
    observaciones?: string | null;
};

type Recibo = {
    id: number;
    folio: string | null;
    estado: 'borrador' | 'emitido';
    estado_etiqueta: string;
    total_percepciones: string;
    total_deducciones: string;
    neto: string;
    observaciones: string | null;
    tiene_pdf: boolean;
    pdf_url: string | null;
    conceptos: Concepto[];
    colaborador: {
        id: number;
        nombre: string;
        numero_empleado: string | null;
        sucursal: string | null;
        puesto: string | null;
    };
};

type Periodo = {
    clave: string;
    etiqueta: string;
    inicio: string;
    fin: string;
    pago: string;
};

const props = defineProps<{
    periodo: Periodo;
    periodos: Periodo[];
    resumen: {
        total: number;
        borradores: number;
        emitidos: number;
        neto: number;
        sin_pdf: number;
    };
    recibos: RespuestaPaginada<Recibo>;
    filtros: { q: string; estado: string };
    config: { emision_automatica: boolean; dias_anticipacion: number };
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recibos de nómina', href: index() },
        ],
    },
});

const { confirmarAccion } = useAlertas();

const dinero = (valor: number | string) =>
    Number(valor).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
    });

const fecha = (valor: string) =>
    new Date(`${valor}T12:00:00`).toLocaleDateString('es-MX', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

// --- Filtros ----------------------------------------------------------------
const busqueda = ref(props.filtros.q);
const estado = ref<string | null>(props.filtros.estado || null);

function recargar(extra: Record<string, unknown> = {}) {
    router.get(
        index.url(),
        {
            periodo: props.periodo.clave,
            q: busqueda.value || undefined,
            estado: estado.value || undefined,
            ...extra,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

let temporizador: ReturnType<typeof setTimeout> | undefined;
watch(busqueda, () => {
    clearTimeout(temporizador);
    temporizador = setTimeout(() => recargar(), 350);
});
watch(estado, () => recargar());

function cambiarPeriodo(clave: string | null) {
    if (clave) {
        seleccion.value = [];
        router.get(index.url(), { periodo: clave });
    }
}

// --- Selección --------------------------------------------------------------
const seleccion = ref<number[]>([]);
const todosMarcados = computed(
    () =>
        props.recibos.data.length > 0 &&
        props.recibos.data.every((r) => seleccion.value.includes(r.id)),
);

function alternarTodos(valor: boolean | (string | number)[]) {
    seleccion.value = valor
        ? [
              ...new Set([
                  ...seleccion.value,
                  ...props.recibos.data.map((r) => r.id),
              ]),
          ]
        : seleccion.value.filter(
              (id) => !props.recibos.data.some((r) => r.id === id),
          );
}

const idsSeleccion = () =>
    seleccion.value.length > 0 ? seleccion.value : undefined;

// --- Acciones de la quincena -----------------------------------------------
const procesando = ref(false);
const opciones = {
    preserveScroll: true,
    onFinish: () => (procesando.value = false),
};

async function prepararQuincena() {
    if (
        !(await confirmarAccion(
            'Preparar recibos',
            `Se crea un borrador para cada colaborador activo con sueldo (${props.periodo.etiqueta}). A quien ya tiene recibo no se le duplica.`,
            'Preparar',
        ))
    ) {
        return;
    }

    procesando.value = true;
    router.post(preparar.url(), { periodo: props.periodo.clave }, opciones);
}

async function emitirQuincena() {
    const cuantos = seleccion.value.length || props.resumen.borradores;

    if (
        !(await confirmarAccion(
            'Emitir recibos',
            `Se emiten ${cuantos} recibo(s): se genera su PDF y cada colaborador lo ve en la app y en su portal.`,
            'Emitir',
        ))
    ) {
        return;
    }

    procesando.value = true;
    router.post(
        emitir.url(),
        { periodo: props.periodo.clave, ids: idsSeleccion() },
        { ...opciones, onSuccess: () => (seleccion.value = []) },
    );
}

function urlDescarga(formato: 'zip' | 'pdf') {
    return descargar.url({
        query: {
            periodo: props.periodo.clave,
            formato,
            ...(seleccion.value.length > 0 ? { ids: seleccion.value } : {}),
        },
    });
}

async function emitirUno(recibo: Recibo) {
    if (
        !(await confirmarAccion(
            'Emitir recibo',
            `Se emite el recibo de ${recibo.colaborador.nombre}.`,
            'Emitir',
        ))
    ) {
        return;
    }

    router.post(emitirRecibo.url(recibo.id), {}, { preserveScroll: true });
}

// --- Cambio en bloque -------------------------------------------------------
const formMasivo = useForm({
    periodo: props.periodo.clave,
    accion: 'agregar' as 'agregar' | 'quitar',
    tipo: 'percepcion' as 'percepcion' | 'deduccion',
    concepto: '',
    importe: '' as string | number,
    ids: undefined as number[] | undefined,
});

async function aplicarMasivo() {
    const alcance = seleccion.value.length
        ? `${seleccion.value.length} recibo(s) seleccionados`
        : `todos los recibos de la quincena (${props.resumen.total})`;
    const que =
        formMasivo.accion === 'agregar'
            ? `agregar/cambiar «${formMasivo.concepto}» por ${dinero(formMasivo.importe || 0)}`
            : `quitar «${formMasivo.concepto}»`;

    if (
        !(await confirmarAccion(
            'Cambio en bloque',
            `Se va a ${que} en ${alcance}. Los recibos ya emitidos regeneran su PDF.`,
            'Aplicar',
        ))
    ) {
        return;
    }

    formMasivo.periodo = props.periodo.clave;
    formMasivo.ids = idsSeleccion();
    formMasivo.post(masivo.url(), {
        preserveScroll: true,
        onSuccess: () => formMasivo.reset('concepto', 'importe'),
    });
}

// --- Edición individual -----------------------------------------------------
const editando = ref<Recibo | null>(null);
const formRecibo = useForm({
    conceptos: [] as Concepto[],
    observaciones: '' as string | null,
});

function abrirEdicion(recibo: Recibo) {
    editando.value = recibo;
    formRecibo.clearErrors();
    formRecibo.conceptos = recibo.conceptos.map((c) => ({
        tipo: c.tipo,
        concepto: c.concepto,
        importe: Number(c.importe),
        observaciones: c.observaciones ?? null,
    }));
    formRecibo.observaciones = recibo.observaciones ?? '';
}

function agregarConcepto(tipo: Concepto['tipo']) {
    formRecibo.conceptos.push({ tipo, concepto: '', importe: 0 });
}

const totalesEdicion = computed(() => {
    const suma = (tipo: Concepto['tipo']) =>
        formRecibo.conceptos
            .filter((c) => c.tipo === tipo)
            .reduce((t, c) => t + (Number(c.importe) || 0), 0);
    const percepciones = suma('percepcion');
    const deducciones = suma('deduccion');

    return { percepciones, deducciones, neto: percepciones - deducciones };
});

function guardarRecibo() {
    if (!editando.value) {
        return;
    }

    formRecibo.put(actualizarRecibo.url(editando.value.id), {
        preserveScroll: true,
        onSuccess: () => (editando.value = null),
    });
}

const opcionesTipo = [
    { value: 'percepcion', label: 'Percepción' },
    { value: 'deduccion', label: 'Deducción' },
];
</script>

<template>
    <Head title="Recibos de nómina" />

    <div class="pagina-ancha flex flex-col gap-6">
        <CrudPageHeader
            titulo="Recibos de nómina"
            descripcion="Recibos quincenales: se preparan solos antes del pago y se emiten en la fecha de pago. Aquí los ajustas y los descargas para entregarlos."
            :icono="ReceiptText"
        >
            <SelectSimple
                :model-value="periodo.clave"
                :opciones="
                    periodos.map((p) => ({ value: p.clave, label: p.etiqueta }))
                "
                class="w-64"
                aria-label="Quincena"
                @update:model-value="(v) => cambiarPeriodo(v as string | null)"
            />
        </CrudPageHeader>

        <div
            class="flex flex-col gap-1 rounded-2xl border border-primary/30 bg-primary/5 p-4 text-sm sm:flex-row sm:items-center sm:justify-between"
        >
            <p>
                <span class="font-semibold">{{ periodo.etiqueta }}</span>
                · del {{ fecha(periodo.inicio) }} al {{ fecha(periodo.fin) }}
                · pago el
                <span class="font-semibold">{{ fecha(periodo.pago) }}</span>
            </p>
            <p class="text-muted-foreground">
                <CalendarClock class="mr-1 inline size-4" />
                <template v-if="config.emision_automatica">
                    Se preparan {{ config.dias_anticipacion }} días antes y se
                    emiten solos el día de pago.
                </template>
                <template v-else>
                    Emisión automática desactivada: emítelos con el botón.
                </template>
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <MetricCard
                etiqueta="Recibos de la quincena"
                :valor="resumen.total"
                :icono="FileStack"
            />
            <MetricCard
                etiqueta="Borradores por emitir"
                :valor="resumen.borradores"
                :icono="Pencil"
                color-clase="text-amber-600"
            />
            <MetricCard
                etiqueta="Emitidos"
                :valor="resumen.emitidos"
                :icono="Send"
                color-clase="text-emerald-600"
            />
            <MetricCard
                etiqueta="Total neto a pagar"
                :valor="dinero(resumen.neto)"
                :icono="Wallet"
            />
        </div>

        <!-- Acciones de la quincena -->
        <div class="flex flex-wrap items-center gap-2">
            <Button
                v-if="puedeEditar"
                variant="secondary"
                :disabled="procesando"
                @click="prepararQuincena"
            >
                <Layers class="size-4" />
                Preparar recibos
            </Button>
            <Button
                v-if="puedeEditar && resumen.borradores > 0"
                :disabled="procesando"
                @click="emitirQuincena"
            >
                <Send class="size-4" />
                {{
                    seleccion.length
                        ? `Emitir seleccionados (${seleccion.length})`
                        : `Emitir borradores (${resumen.borradores})`
                }}
            </Button>
            <div class="ml-auto flex flex-wrap gap-2">
                <Button
                    as-child
                    variant="outline"
                    :disabled="resumen.emitidos === 0"
                >
                    <a :href="urlDescarga('zip')">
                        <Download class="size-4" />
                        Descargar
                        {{ seleccion.length ? 'seleccionados' : 'todos' }}
                        (ZIP)
                    </a>
                </Button>
                <Button
                    as-child
                    variant="outline"
                    :disabled="resumen.emitidos === 0"
                >
                    <a :href="urlDescarga('pdf')">
                        <Printer class="size-4" />
                        Un PDF para imprimir
                    </a>
                </Button>
            </div>
        </div>

        <!-- Cambio en bloque -->
        <section
            v-if="puedeEditar && resumen.total > 0"
            class="rounded-2xl border border-border/60 bg-card p-5"
        >
            <h2 class="text-base font-semibold">Cambiar algo a todos</h2>
            <p class="mb-4 text-sm text-muted-foreground">
                Agrega o quita un concepto (bono, descuento, etc.) a
                {{
                    seleccion.length
                        ? `los ${seleccion.length} recibos seleccionados`
                        : 'todos los recibos de esta quincena'
                }}. Si el concepto ya existe, solo cambia su importe.
            </p>
            <div
                class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-[10rem_10rem_minmax(0,1fr)_10rem_auto]"
            >
                <div class="grid gap-1.5">
                    <Label>Acción</Label>
                    <SelectSimple
                        v-model="formMasivo.accion"
                        :opciones="[
                            { value: 'agregar', label: 'Agregar / cambiar' },
                            { value: 'quitar', label: 'Quitar' },
                        ]"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Tipo</Label>
                    <SelectSimple
                        v-model="formMasivo.tipo"
                        :opciones="opcionesTipo"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label>Concepto</Label>
                    <Input
                        v-model="formMasivo.concepto"
                        placeholder="Ej. Bono de puntualidad"
                    />
                    <InputError :message="formMasivo.errors.concepto" />
                </div>
                <div
                    v-if="formMasivo.accion === 'agregar'"
                    class="grid gap-1.5"
                >
                    <Label>Importe</Label>
                    <Input
                        v-model="formMasivo.importe"
                        type="number"
                        min="0"
                        step="0.01"
                        placeholder="0.00"
                    />
                    <InputError :message="formMasivo.errors.importe" />
                </div>
                <Button
                    :disabled="
                        formMasivo.processing ||
                        !formMasivo.concepto.trim() ||
                        (formMasivo.accion === 'agregar' &&
                            formMasivo.importe === '')
                    "
                    @click="aplicarMasivo"
                >
                    Aplicar
                </Button>
            </div>
        </section>

        <!-- Listado -->
        <section class="rounded-2xl border border-border/60 bg-card">
            <div
                class="flex flex-col gap-3 border-b border-border/60 p-4 sm:flex-row sm:items-center"
            >
                <Input
                    v-model="busqueda"
                    placeholder="Buscar por nombre o número de empleado"
                    class="sm:max-w-sm"
                />
                <SelectSimple
                    v-model="estado"
                    opcion-vacia="Todos los estados"
                    :opciones="[
                        { value: 'borrador', label: 'Borradores' },
                        { value: 'emitido', label: 'Emitidos' },
                    ]"
                    class="sm:w-52"
                />
                <p
                    v-if="seleccion.length"
                    class="text-sm text-muted-foreground sm:ml-auto"
                >
                    {{ seleccion.length }} seleccionado(s) ·
                    <button
                        type="button"
                        class="text-primary underline"
                        @click="seleccion = []"
                    >
                        quitar selección
                    </button>
                </p>
            </div>

            <EmptyState
                v-if="recibos.data.length === 0"
                :icono="ReceiptText"
                titulo="Sin recibos en esta quincena"
                :descripcion="
                    puedeEditar
                        ? 'Usa «Preparar recibos» para crearlos ahora, o espera a que el sistema los prepare solo antes del pago.'
                        : 'Todavía no hay recibos para esta quincena.'
                "
            />

            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead
                        class="bg-muted/40 text-left text-xs text-muted-foreground uppercase"
                    >
                        <tr>
                            <th class="w-10 p-3">
                                <Casilla
                                    :model-value="todosMarcados"
                                    aria-label="Seleccionar todos"
                                    @update:model-value="alternarTodos"
                                />
                            </th>
                            <th class="p-3">Colaborador</th>
                            <th class="p-3">Sucursal</th>
                            <th class="p-3 text-right">Percepciones</th>
                            <th class="p-3 text-right">Deducciones</th>
                            <th class="p-3 text-right">Neto</th>
                            <th class="p-3">Estado</th>
                            <th class="p-3 text-right">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="recibo in recibos.data"
                            :key="recibo.id"
                            class="border-t border-border/60"
                        >
                            <td class="p-3">
                                <Casilla
                                    v-model="seleccion"
                                    :value="recibo.id"
                                    :aria-label="`Seleccionar ${recibo.colaborador.nombre}`"
                                />
                            </td>
                            <td class="p-3">
                                <p class="font-medium">
                                    {{ recibo.colaborador.nombre }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    {{
                                        [
                                            recibo.colaborador.numero_empleado
                                                ? `N.º ${recibo.colaborador.numero_empleado}`
                                                : null,
                                            recibo.colaborador.puesto,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </p>
                            </td>
                            <td class="p-3">
                                {{ recibo.colaborador.sucursal ?? '—' }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ dinero(recibo.total_percepciones) }}
                            </td>
                            <td class="p-3 text-right tabular-nums">
                                {{ dinero(recibo.total_deducciones) }}
                            </td>
                            <td
                                class="p-3 text-right font-semibold tabular-nums"
                            >
                                {{ dinero(recibo.neto) }}
                            </td>
                            <td class="p-3">
                                <span
                                    class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                                    :class="
                                        recibo.estado === 'emitido'
                                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
                                            : 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                                    "
                                    >{{ recibo.estado_etiqueta }}</span
                                >
                            </td>
                            <td class="p-3">
                                <div class="flex justify-end gap-1">
                                    <Button
                                        v-if="puedeEditar"
                                        size="sm"
                                        variant="ghost"
                                        @click="abrirEdicion(recibo)"
                                    >
                                        <Pencil class="size-4" />
                                        Editar
                                    </Button>
                                    <Button
                                        v-if="
                                            puedeEditar &&
                                            recibo.estado === 'borrador'
                                        "
                                        size="sm"
                                        variant="ghost"
                                        @click="emitirUno(recibo)"
                                    >
                                        <Send class="size-4" />
                                        Emitir
                                    </Button>
                                    <Button
                                        v-if="recibo.pdf_url"
                                        as-child
                                        size="sm"
                                        variant="ghost"
                                    >
                                        <a
                                            :href="recibo.pdf_url"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            <FileText class="size-4" />
                                            PDF
                                        </a>
                                    </Button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="recibos.last_page > 1"
                class="flex items-center justify-between border-t border-border/60 p-4 text-sm"
            >
                <span class="text-muted-foreground"
                    >{{ recibos.from }}–{{ recibos.to }} de
                    {{ recibos.total }}</span
                >
                <div class="flex gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="recibos.current_page <= 1"
                        @click="recargar({ page: recibos.current_page - 1 })"
                    >
                        Anterior
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="recibos.current_page >= recibos.last_page"
                        @click="recargar({ page: recibos.current_page + 1 })"
                    >
                        Siguiente
                    </Button>
                </div>
            </div>
        </section>
    </div>

    <!-- Editar un recibo -->
    <Dialog
        :open="editando !== null"
        @update:open="(v: boolean) => !v && (editando = null)"
    >
        <DialogContent class="sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle
                    >Editar recibo —
                    {{ editando?.colaborador.nombre }}</DialogTitle
                >
                <DialogDescription>
                    {{
                        editando?.estado === 'emitido'
                            ? 'Este recibo ya se emitió: al guardar se vuelve a generar su PDF (el anterior queda en el historial).'
                            : 'Borrador: el colaborador lo verá cuando se emita.'
                    }}
                </DialogDescription>
            </DialogHeader>

            <div class="flex max-h-[60vh] flex-col gap-3 overflow-y-auto pr-1">
                <div
                    v-for="(concepto, i) in formRecibo.conceptos"
                    :key="i"
                    class="grid items-start gap-2 sm:grid-cols-[9rem_minmax(0,1fr)_9rem_auto]"
                >
                    <SelectSimple
                        v-model="concepto.tipo"
                        :opciones="opcionesTipo"
                        :aria-label="`Tipo del concepto ${i + 1}`"
                    />
                    <div>
                        <Input
                            v-model="concepto.concepto"
                            placeholder="Concepto"
                        />
                        <InputError
                            :message="
                                (formRecibo.errors as Record<string, string>)[
                                    `conceptos.${i}.concepto`
                                ]
                            "
                        />
                    </div>
                    <div>
                        <Input
                            v-model="concepto.importe"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <InputError
                            :message="
                                (formRecibo.errors as Record<string, string>)[
                                    `conceptos.${i}.importe`
                                ]
                            "
                        />
                    </div>
                    <Button
                        variant="ghost"
                        size="icon"
                        :disabled="formRecibo.conceptos.length <= 1"
                        :aria-label="`Quitar concepto ${i + 1}`"
                        @click="formRecibo.conceptos.splice(i, 1)"
                    >
                        <Trash2 class="size-4" />
                    </Button>
                </div>

                <div class="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        @click="agregarConcepto('percepcion')"
                    >
                        <Plus class="size-4" />
                        Percepción
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        @click="agregarConcepto('deduccion')"
                    >
                        <Plus class="size-4" />
                        Deducción
                    </Button>
                </div>
                <InputError :message="formRecibo.errors.conceptos" />

                <div class="grid gap-1.5">
                    <Label>Observaciones</Label>
                    <Textarea
                        v-model="formRecibo.observaciones as string"
                        rows="2"
                    />
                </div>

                <div
                    class="grid grid-cols-3 gap-2 rounded-xl bg-muted/50 p-3 text-sm"
                >
                    <div>
                        <p class="text-muted-foreground">Percepciones</p>
                        <p class="font-semibold">
                            {{ dinero(totalesEdicion.percepciones) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Deducciones</p>
                        <p class="font-semibold">
                            {{ dinero(totalesEdicion.deducciones) }}
                        </p>
                    </div>
                    <div>
                        <p class="text-muted-foreground">Neto</p>
                        <p class="text-base font-bold">
                            {{ dinero(totalesEdicion.neto) }}
                        </p>
                    </div>
                </div>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="editando = null"
                    >Cancelar</Button
                >
                <Button
                    :disabled="formRecibo.processing"
                    @click="guardarRecibo"
                >
                    Guardar
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
