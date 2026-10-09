<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    CircleX,
    FileText,
    Pencil,
    Plus,
    ReceiptText,
    Send,
    Trash2,
    Users,
    Wallet,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import EmptyState from '@/components/Common/EmptyState.vue';
import InputError from '@/components/InputError.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Textarea } from '@/components/ui/textarea';
import { useAlertas } from '@/composables/useAlertas';
import { getJson } from '@/lib/http';
import { dashboard } from '@/routes';
import { cancelar, emitir, index, show } from '@/routes/rh/nomina/lotes';
import {
    conceptos as conceptosRecibo,
    pdf as pdfRecibo,
} from '@/routes/rh/nomina/lotes/recibos';
import { update as actualizarRecibo } from '@/routes/rh/nomina/recibos';
import type { LoteNomina, ReciboLote } from '@/types';

/**
 * Revisión masiva de un lote: lista de colaboradores a la izquierda y el PDF
 * REAL (formato oficial) a la derecha, con Anterior/Siguiente y filtros.
 * Nada se publica hasta «Emitir N recibos».
 */
type Filtro = 'todos' | 'correctos' | 'advertencias' | 'errores';

const props = defineProps<{
    lote: LoteNomina;
    recibos: ReciboLote[];
    conteos: Record<Filtro, number>;
    filtro: Filtro;
    puedeEmitir: boolean;
    puedeCancelar: boolean;
    puedeEditar: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Inicio', href: dashboard() },
            { title: 'Recibos de nómina', href: index() },
            { title: 'Revisión del lote', href: '#' },
        ],
    },
});

const { confirmarAccion } = useAlertas();

const dinero = (valor: number | string | null) =>
    valor === null
        ? '—'
        : Number(valor).toLocaleString('es-MX', {
              style: 'currency',
              currency: 'MXN',
          });

const FILTROS: { clave: Filtro; etiqueta: string }[] = [
    { clave: 'todos', etiqueta: 'Todos' },
    { clave: 'correctos', etiqueta: 'Correctos' },
    { clave: 'advertencias', etiqueta: 'Advertencias' },
    { clave: 'errores', etiqueta: 'Errores' },
];

function filtrar(filtro: Filtro) {
    router.get(
        show.url(props.lote.id),
        { filtro },
        { preserveScroll: true, preserveState: true, only: ['recibos', 'filtro'] },
    );
}

// --- Selección y navegación --------------------------------------------------
const indice = ref(0);
const seleccionado = computed<ReciboLote | null>(
    () => props.recibos[indice.value] ?? null,
);
const pdfUrl = computed(() =>
    seleccionado.value?.id
        ? pdfRecibo.url({ lote: props.lote.id, recibo: seleccionado.value.id })
        : null,
);
const cargandoPdf = ref(true);
const pdfMovil = ref(false);

watch(
    () => props.recibos,
    () => {
        indice.value = 0;
    },
);
watch(pdfUrl, () => {
    cargandoPdf.value = true;
});

function elegir(i: number) {
    indice.value = i;

    if (window.matchMedia('(max-width: 1023px)').matches) {
        pdfMovil.value = true;
    }
}

function mover(paso: number) {
    const siguiente = indice.value + paso;

    if (siguiente >= 0 && siguiente < props.recibos.length) {
        indice.value = siguiente;
    }
}

const ESTILO_REVISION: Record<string, { clase: string; icono: typeof CheckCircle2; texto: string }> = {
    correcto: {
        clase: 'bg-success-soft/60 text-success',
        icono: CheckCircle2,
        texto: 'Correcto',
    },
    advertencia: {
        clase: 'bg-warning-soft text-warning',
        icono: AlertTriangle,
        texto: 'Advertencia',
    },
    error: {
        clase: 'bg-destructive/10 text-destructive',
        icono: CircleX,
        texto: 'Error',
    },
};

// --- Emitir / cancelar -------------------------------------------------------
const emitiendo = ref(false);

async function emitirLote() {
    const ok = await confirmarAccion(
        `Emitir ${props.lote.por_emitir} recibos`,
        `Se publicarán ${props.lote.por_emitir} recibos y se notificará a cada colaborador (web, app y push). Esta acción no se puede deshacer.`,
        'Emitir y notificar',
    );

    if (!ok) {
        return;
    }

    emitiendo.value = true;
    router.post(emitir.url(props.lote.id), {}, {
        preserveScroll: true,
        onFinish: () => (emitiendo.value = false),
    });
}

const cancelarAbierto = ref(false);
const cancelarForm = useForm({ motivo: '' });

function cancelarLote() {
    cancelarForm.post(cancelar.url(props.lote.id), {
        preserveScroll: true,
        onSuccess: () => {
            cancelarAbierto.value = false;
            cancelarForm.reset();
        },
    });
}

// --- Editar conceptos de un recibo en revisión -------------------------------
type ConceptoEditable = {
    tipo: 'percepcion' | 'deduccion';
    clave: string;
    concepto: string;
    cantidad: number;
    importe: number;
    observaciones: string | null;
};

const editarAbierto = ref(false);
const editarForm = useForm<{ conceptos: ConceptoEditable[]; observaciones: string }>({
    conceptos: [],
    observaciones: '',
});
const editandoId = ref<number | null>(null);

async function abrirEdicion() {
    if (!seleccionado.value?.id) {
        return;
    }

    editandoId.value = seleccionado.value.id;
    const respuesta = await getJson<{ conceptos: (Omit<ConceptoEditable, 'clave'> & { clave: string | null })[]; observaciones: string | null }>(
        conceptosRecibo.url({ lote: props.lote.id, recibo: seleccionado.value.id }),
    );
    editarForm.conceptos = respuesta.conceptos.map((c) => ({ ...c, clave: c.clave ?? '' }));
    editarForm.observaciones = respuesta.observaciones ?? '';
    editarForm.clearErrors();
    editarAbierto.value = true;
}

function agregarConcepto(tipo: 'percepcion' | 'deduccion') {
    editarForm.conceptos.push({ tipo, clave: '', concepto: '', cantidad: 1, importe: 0, observaciones: null });
}

const totalEditado = computed(() =>
    editarForm.conceptos.reduce(
        (total, c) => total + (c.tipo === 'percepcion' ? 1 : -1) * Number(c.importe || 0),
        0,
    ),
);

function guardarEdicion() {
    if (editandoId.value === null) {
        return;
    }

    editarForm.put(actualizarRecibo.url(editandoId.value), {
        preserveScroll: true,
        onSuccess: () => {
            editarAbierto.value = false;
            cargandoPdf.value = true;
            router.reload({ only: ['lote', 'recibos'] });
        },
    });
}
</script>

<template>
    <Head :title="`Lote ${lote.etiqueta}`" />

    <div class="pagina-ancha flex flex-col gap-5">
        <!-- Encabezado -->
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="flex items-start gap-3">
                <Button variant="ghost" size="icon" as-child class="mt-1 shrink-0">
                    <Link :href="index()" aria-label="Volver a lotes">
                        <ArrowLeft class="size-4" />
                    </Link>
                </Button>
                <div>
                    <p class="text-xs font-semibold tracking-[0.2em] text-oro uppercase">
                        Periodo
                    </p>
                    <h1 class="text-2xl font-semibold">{{ lote.etiqueta }}</h1>
                    <p class="text-sm text-muted-foreground">
                        {{ lote.folio }} · No. de nómina {{ lote.numero_nomina ?? '—' }}
                        · {{ lote.origen === 'importacion' ? `Importado (${lote.archivo_nombre})` : 'Desde sueldos' }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Badge
                    :variant="lote.estado === 'cancelado' ? 'destructive' : 'secondary'"
                    class="px-3 py-1 text-xs"
                >
                    {{ lote.estado_etiqueta }}
                </Badge>
                <Button
                    v-if="puedeCancelar"
                    variant="outline"
                    @click="cancelarAbierto = true"
                >
                    <CircleX class="size-4" />
                    Cancelar lote
                </Button>
                <Button
                    v-if="puedeEmitir"
                    size="lg"
                    :disabled="emitiendo || lote.por_emitir === 0"
                    @click="emitirLote"
                >
                    <Send class="size-4" />
                    Emitir {{ lote.por_emitir }} recibos
                </Button>
            </div>
        </div>

        <div
            v-if="lote.estado === 'preparado'"
            class="flex items-start gap-3 rounded-2xl border border-oro/40 bg-warning-soft/40 p-4 text-sm"
        >
            <AlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
            <p>
                <strong>Todavía no se publica.</strong> Los recibos de este lote no
                son visibles para los colaboradores y no se ha enviado ningún aviso.
                Revisa y pulsa «Emitir» cuando todo esté correcto.
            </p>
        </div>
        <div
            v-else-if="lote.estado === 'emitido'"
            class="flex items-start gap-3 rounded-2xl border border-success/30 bg-success-soft/60 p-4 text-sm"
        >
            <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-success" />
            <p>
                Emitido por <strong>{{ lote.emitido_por }}</strong> el
                {{ new Date(lote.emitido_at ?? '').toLocaleString('es-MX') }}. Cada
                colaborador ya ve su recibo en web y app.
            </p>
        </div>
        <div
            v-else-if="lote.estado === 'cancelado'"
            class="flex items-start gap-3 rounded-2xl border border-destructive/30 bg-destructive/5 p-4 text-sm"
        >
            <CircleX class="mt-0.5 size-4 shrink-0 text-destructive" />
            <p>
                Cancelado por <strong>{{ lote.cancelado_por }}</strong>:
                {{ lote.motivo_cancelacion }}
            </p>
        </div>

        <!-- Resumen -->
        <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><Users class="size-3.5" /> Esperados</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ lote.esperados }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><ReceiptText class="size-3.5" /> Preparados</p>
                <p class="mt-1 text-2xl font-bold tabular-nums">{{ lote.preparados }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><CircleX class="size-3.5" /> Errores</p>
                <p class="mt-1 text-2xl font-bold tabular-nums" :class="lote.total_errores ? 'text-destructive' : ''">{{ lote.total_errores }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="flex items-center gap-1.5 text-xs text-muted-foreground"><AlertTriangle class="size-3.5" /> Advertencias</p>
                <p class="mt-1 text-2xl font-bold tabular-nums" :class="lote.total_advertencias ? 'text-warning' : ''">{{ lote.total_advertencias }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">Total percepciones</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ dinero(lote.total_percepciones) }}</p>
            </div>
            <div class="rounded-2xl border border-border/60 bg-card p-4">
                <p class="text-xs text-muted-foreground">Total deducciones</p>
                <p class="mt-1 text-lg font-semibold tabular-nums">{{ dinero(lote.total_deducciones) }}</p>
            </div>
            <div class="col-span-2 rounded-2xl bg-primary p-4 text-primary-foreground md:col-span-1">
                <p class="flex items-center gap-1.5 text-xs opacity-80"><Wallet class="size-3.5" /> Total neto</p>
                <p class="mt-1 text-lg font-bold tabular-nums">{{ dinero(lote.total_neto) }}</p>
            </div>
        </div>

        <!-- Filtros -->
        <div class="scroll-x-limpio flex gap-2">
            <button
                v-for="f in FILTROS"
                :key="f.clave"
                type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-1.5 text-sm transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    filtro === f.clave
                        ? 'border-primary bg-primary text-primary-foreground'
                        : 'border-border bg-card hover:bg-muted'
                "
                @click="filtrar(f.clave)"
            >
                {{ f.etiqueta }}
                <span
                    class="rounded-full px-1.5 text-xs tabular-nums"
                    :class="filtro === f.clave ? 'bg-white/20' : 'bg-muted'"
                    >{{ conteos[f.clave] }}</span
                >
            </button>
        </div>

        <EmptyState
            v-if="recibos.length === 0"
            :icono="ReceiptText"
            titulo="Nada en este filtro"
            descripcion="Cambia el filtro para ver el resto de los recibos del lote."
        />

        <!-- Revisión: lista + PDF real -->
        <div v-else class="grid gap-4 lg:grid-cols-[minmax(18rem,26rem)_1fr]">
            <ul
                class="flex max-h-[75vh] flex-col gap-2 overflow-y-auto pr-1"
                aria-label="Recibos del lote"
            >
                <li v-for="(recibo, i) in recibos" :key="recibo.id ?? `e${recibo.clave_error}`">
                    <button
                        type="button"
                        class="w-full rounded-2xl border p-3 text-left transition focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            i === indice
                                ? 'border-primary bg-primary/5 ring-1 ring-primary'
                                : 'border-border/60 bg-card hover:border-primary/30 hover:bg-muted/40'
                        "
                        @click="elegir(i)"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold">{{ recibo.nombre }}</p>
                                <p class="truncate text-xs text-muted-foreground">
                                    {{ recibo.numero_empleado ?? '—' }}
                                    <template v-if="recibo.sucursal"> · {{ recibo.sucursal }}</template>
                                </p>
                            </div>
                            <span
                                class="inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
                                :class="ESTILO_REVISION[recibo.revision].clase"
                            >
                                <component :is="ESTILO_REVISION[recibo.revision].icono" class="size-3" />
                                {{ ESTILO_REVISION[recibo.revision].texto }}
                            </span>
                        </div>
                        <div v-if="recibo.id" class="mt-2 grid grid-cols-3 gap-1 text-[11px]">
                            <span class="text-muted-foreground">Perc. <b class="text-foreground tabular-nums">{{ dinero(recibo.total_percepciones) }}</b></span>
                            <span class="text-muted-foreground">Ded. <b class="text-foreground tabular-nums">{{ dinero(recibo.total_deducciones) }}</b></span>
                            <span class="text-right font-semibold tabular-nums">{{ dinero(recibo.neto) }}</span>
                        </div>
                        <p
                            v-for="aviso in recibo.advertencias"
                            :key="aviso"
                            class="mt-1.5 text-[11px]"
                            :class="recibo.revision === 'error' ? 'text-destructive' : 'text-warning'"
                        >
                            {{ aviso }}
                        </p>
                    </button>
                </li>
            </ul>

            <section
                class="hidden min-h-[75vh] flex-col overflow-hidden rounded-2xl border border-border/60 bg-card lg:flex"
                aria-label="Vista previa del recibo"
            >
                <header class="flex flex-wrap items-center justify-between gap-3 border-b border-border/60 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ seleccionado?.nombre }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ seleccionado?.puesto ?? '' }}
                            <template v-if="seleccionado?.folio"> · {{ seleccionado.folio }}</template>
                            · {{ indice + 1 }} de {{ recibos.length }}
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="puedeEditar && seleccionado?.id"
                            variant="outline"
                            size="sm"
                            @click="abrirEdicion"
                        >
                            <Pencil class="size-3.5" /> Editar conceptos
                        </Button>
                        <Button variant="outline" size="sm" :disabled="indice === 0" @click="mover(-1)">
                            <ChevronLeft class="size-4" /> Anterior
                        </Button>
                        <Button variant="outline" size="sm" :disabled="indice >= recibos.length - 1" @click="mover(1)">
                            Siguiente <ChevronRight class="size-4" />
                        </Button>
                    </div>
                </header>
                <div class="relative flex-1 bg-muted/40">
                    <div
                        v-if="pdfUrl && cargandoPdf"
                        class="absolute inset-0 flex items-center justify-center"
                    >
                        <div class="h-[85%] w-[70%] animate-pulse rounded-lg bg-muted" />
                    </div>
                    <iframe
                        v-if="pdfUrl"
                        :key="pdfUrl"
                        :src="pdfUrl"
                        title="Recibo de nómina (formato oficial)"
                        class="relative h-full w-full"
                        @load="cargandoPdf = false"
                    />
                    <div v-else class="flex h-full items-center justify-center p-8">
                        <EmptyState
                            :icono="FileText"
                            titulo="Sin recibo"
                            :descripcion="seleccionado?.advertencias[0] ?? 'Esta persona no tiene recibo en el lote.'"
                        />
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- PDF en móvil -->
    <Sheet v-model:open="pdfMovil">
        <SheetContent side="bottom" class="h-[92vh] gap-0 p-0">
            <SheetHeader class="border-b">
                <SheetTitle class="truncate">{{ seleccionado?.nombre }}</SheetTitle>
                <div class="flex gap-2">
                    <Button variant="outline" size="sm" class="flex-1" :disabled="indice === 0" @click="mover(-1)">
                        <ChevronLeft class="size-4" /> Anterior
                    </Button>
                    <Button variant="outline" size="sm" class="flex-1" :disabled="indice >= recibos.length - 1" @click="mover(1)">
                        Siguiente <ChevronRight class="size-4" />
                    </Button>
                </div>
            </SheetHeader>
            <iframe v-if="pdfUrl" :key="`m${pdfUrl}`" :src="pdfUrl" title="Recibo de nómina" class="h-full w-full" />
            <p v-else class="p-6 text-sm text-destructive">{{ seleccionado?.advertencias[0] }}</p>
        </SheetContent>
    </Sheet>

    <!-- Cancelar -->
    <Dialog v-model:open="cancelarAbierto">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancelar lote</DialogTitle>
                <DialogDescription>
                    El lote y sus recibos se conservan como cancelados (si ya se
                    habían emitido, dejan de verse en web y app). El periodo podrá
                    volver a prepararse.
                </DialogDescription>
            </DialogHeader>
            <div class="grid gap-2">
                <Label for="motivo-cancelacion">Motivo</Label>
                <Textarea id="motivo-cancelacion" v-model="cancelarForm.motivo" rows="3" />
                <InputError :message="cancelarForm.errors.motivo ?? (cancelarForm.errors as Record<string, string>).lote" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="cancelarAbierto = false">Volver</Button>
                <Button variant="destructive" :disabled="cancelarForm.processing" @click="cancelarLote">
                    Cancelar lote
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>

    <!-- Editar conceptos -->
    <Dialog v-model:open="editarAbierto">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
            <DialogHeader>
                <DialogTitle>Conceptos del recibo</DialogTitle>
                <DialogDescription>
                    El total lo recalcula el servidor. El recibo sigue sin publicarse.
                </DialogDescription>
            </DialogHeader>

            <div class="flex flex-col gap-2">
                <div
                    v-for="(c, i) in editarForm.conceptos"
                    :key="i"
                    class="grid grid-cols-[5.5rem_4.5rem_1fr_7rem_auto] items-center gap-2"
                >
                    <span
                        class="rounded-full px-2 py-1 text-center text-[11px] font-medium"
                        :class="c.tipo === 'percepcion' ? 'bg-success-soft/60 text-success' : 'bg-destructive/10 text-destructive'"
                    >
                        {{ c.tipo === 'percepcion' ? 'Percepción' : 'Deducción' }}
                    </span>
                    <Input v-model="c.clave" placeholder="Clave" aria-label="Clave" />
                    <Input v-model="c.concepto" placeholder="Concepto" aria-label="Concepto" />
                    <Input v-model.number="c.importe" type="number" min="0" step="0.01" aria-label="Importe" />
                    <Button variant="ghost" size="icon" aria-label="Quitar concepto" @click="editarForm.conceptos.splice(i, 1)">
                        <Trash2 class="size-4" />
                    </Button>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" @click="agregarConcepto('percepcion')">
                        <Plus class="size-3.5" /> Percepción
                    </Button>
                    <Button variant="outline" size="sm" @click="agregarConcepto('deduccion')">
                        <Plus class="size-3.5" /> Deducción
                    </Button>
                </div>
                <InputError :message="editarForm.errors.conceptos" />
                <div class="grid gap-2">
                    <Label for="obs-recibo">Observaciones</Label>
                    <Textarea id="obs-recibo" v-model="editarForm.observaciones" rows="2" />
                </div>
                <p class="text-right text-sm text-muted-foreground">
                    Neto estimado: <strong class="text-foreground">{{ dinero(totalEditado) }}</strong>
                </p>
            </div>

            <DialogFooter>
                <Button variant="outline" @click="editarAbierto = false">Cancelar</Button>
                <Button :disabled="editarForm.processing" @click="guardarEdicion">Guardar</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
