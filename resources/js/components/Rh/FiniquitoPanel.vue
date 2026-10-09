<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import {
    Calculator,
    Download,
    Eye,
    FileCheck2,
    Pencil,
    Plus,
    RefreshCw,
    Trash2,
    Upload,
    X,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import SelectSimple from '@/components/Common/SelectSimple.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
    ajustes,
    calcular,
    descargarPdf,
    firmado,
    generarPdf,
    recalcular,
    revisar,
    vistaPrevia,
} from '@/routes/rh/solicitudes/finiquito';
import {
    ajustar as ajustarConceptoRoute,
    destroy as eliminarConceptoRoute,
    store as agregarConceptoRoute,
    update as actualizarConceptoRoute,
} from '@/routes/rh/solicitudes/finiquito/conceptos';
import type {
    FiniquitoCalculoItem,
    FiniquitoDesgloseItem,
    FiniquitoPermisos,
} from '@/types';

const props = defineProps<{
    solicitudId: number;
    finiquito: FiniquitoCalculoItem | null;
    desglose: FiniquitoDesgloseItem[];
    permisos: FiniquitoPermisos;
}>();

function moneda(valor: string | number): string {
    return `$${Number(valor).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const formCalculo = useForm({
    sueldo_mensual: props.finiquito?.sueldo_mensual ?? '',
    sueldo_pendiente: props.finiquito?.sueldo_pendiente ?? '0',
});

const sueldoInvalido = computed(
    () =>
        formCalculo.sueldo_mensual === '' ||
        Number(formCalculo.sueldo_mensual) <= 0,
);

function calcularFiniquito() {
    if (sueldoInvalido.value) {
        return;
    }

    formCalculo.post(calcular.url(props.solicitudId), { preserveScroll: true });
}

function recalcularFiniquito() {
    if (sueldoInvalido.value) {
        return;
    }

    formCalculo.post(recalcular.url(props.solicitudId), {
        preserveScroll: true,
    });
}

const formAjustes = useForm({
    bonos_extra: props.finiquito?.bonos_extra ?? '0',
    descuentos: props.finiquito?.descuentos ?? '0',
    adeudos: props.finiquito?.adeudos ?? '0',
    comentarios_ajuste: props.finiquito?.comentarios_ajuste ?? '',
});

const totalAjustadoPreview = computed(() => {
    if (!props.finiquito) {
        return 0;
    }

    const base = Number(props.finiquito.total_calculado);
    const bonos = Number(formAjustes.bonos_extra) || 0;
    const descuentos = Number(formAjustes.descuentos) || 0;
    const adeudos = Number(formAjustes.adeudos) || 0;

    return base + bonos - descuentos - adeudos;
});

function guardarAjustes() {
    formAjustes.put(ajustes.url(props.solicitudId), { preserveScroll: true });
}

const formRevisar = useForm({});
function revisarFiniquito() {
    formRevisar.post(revisar.url(props.solicitudId), { preserveScroll: true });
}

const formGenerarPdf = useForm({});
function generarPdfFiniquito() {
    formGenerarPdf.post(generarPdf.url(props.solicitudId), {
        preserveScroll: true,
    });
}

const formFirmado = useForm({ archivo: null as File | null });
const inputFirmado = ref<HTMLInputElement | null>(null);

function subirFirmado(event: Event) {
    const input = event.target as HTMLInputElement;
    formFirmado.archivo = input.files?.[0] ?? null;

    if (!formFirmado.archivo) {
        return;
    }

    formFirmado.post(firmado.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => {
            formFirmado.reset();
            input.value = '';
        },
    });
}

// Firmado o pagado: el documento entregado ya no se mueve (mismo criterio
// que FiniquitoService::asegurarNoFirmado()).
const cerrado = computed(
    () =>
        props.finiquito !== null &&
        ['firmado', 'pagado'].includes(props.finiquito.estado),
);

const puedeEditarAjustes = computed(
    () => props.finiquito !== null && !cerrado.value,
);

const TIPOS_CONCEPTO = [
    { value: 'percepcion', label: 'Percepción' },
    { value: 'deduccion', label: 'Deducción' },
];

// Desglose editable antes del PDF (CLAUDE.md §20): los automáticos solo se
// muestran, los capturados por RH (origen "manual") se pueden editar o
// quitar — el total siempre lo recalcula el servidor (FiniquitoService::recalcularTotales()).
const formNuevoConcepto = useForm({
    tipo: 'percepcion',
    concepto: '',
    cantidad: '1',
    importe: '0',
    observaciones: '',
});
const agregandoConcepto = ref(false);

function abrirNuevoConcepto() {
    formNuevoConcepto.reset();
    agregandoConcepto.value = true;
}

function guardarNuevoConcepto() {
    formNuevoConcepto.post(agregarConceptoRoute.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => (agregandoConcepto.value = false),
    });
}

const editandoConceptoId = ref<number | null>(null);
const formEditarConcepto = useForm({
    tipo: 'percepcion',
    concepto: '',
    cantidad: '1',
    importe: '0',
    observaciones: '',
});

function abrirEdicionConcepto(fila: FiniquitoDesgloseItem) {
    if (fila.id === null) {
        return;
    }

    editandoConceptoId.value = fila.id;
    formEditarConcepto.tipo = fila.tipo;
    formEditarConcepto.concepto = fila.concepto;
    formEditarConcepto.cantidad = String(fila.cantidad);
    formEditarConcepto.importe = String(fila.importe);
    formEditarConcepto.observaciones = fila.observaciones ?? '';
}

function guardarEdicionConcepto() {
    if (editandoConceptoId.value === null) {
        return;
    }

    formEditarConcepto.patch(
        actualizarConceptoRoute.url([
            props.solicitudId,
            editandoConceptoId.value,
        ]),
        {
            preserveScroll: true,
            onSuccess: () => (editandoConceptoId.value = null),
        },
    );
}

// Ajuste AUTORIZADO de un concepto automático (001–004, 101–102…): se
// guarda el valor calculado, el final, el motivo, quién y cuándo; el total
// lo recalcula el servidor.
const ajustandoClave = ref<string | null>(null);
const formAjuste = useForm({ concepto_clave: '', importe: '0', motivo: '' });

function abrirAjuste(fila: FiniquitoDesgloseItem) {
    if (!fila.concepto_clave) {
        return;
    }

    ajustandoClave.value = fila.concepto_clave;
    formAjuste.concepto_clave = fila.concepto_clave;
    formAjuste.importe = String(fila.importe);
    formAjuste.motivo = '';
    formAjuste.clearErrors();
}

function guardarAjuste() {
    formAjuste.post(ajustarConceptoRoute.url(props.solicitudId), {
        preserveScroll: true,
        onSuccess: () => (ajustandoClave.value = null),
    });
}

function eliminarConcepto(id: number) {
    router.delete(
        eliminarConceptoRoute.url([props.solicitudId, id]),
        { preserveScroll: true },
    );
}
</script>

<template>
    <div class="rounded-2xl border border-border/60 bg-card p-5">
        <div class="mb-3 flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold">Finiquito</h3>
            <EstadoBadge v-if="finiquito" :estado="finiquito.estado" />
        </div>

        <p
            v-if="!finiquito && !permisos.puedeCalcular"
            class="text-sm text-muted-foreground"
        >
            Todavía no se ha calculado el finiquito de esta baja.
        </p>

        <!-- Sin cálculo todavía: capturar sueldo mensual y calcular. -->
        <div v-else-if="!finiquito" class="flex flex-col gap-3">
            <p class="text-sm text-muted-foreground">
                Captura el sueldo mensual del colaborador para calcular su
                finiquito. El cálculo es automático pero queda abierto a ajustes
                antes de revisarse.
            </p>
            <div class="flex flex-wrap items-end gap-2">
                <div class="grid gap-1.5">
                    <Label for="sueldo_mensual">Sueldo mensual</Label>
                    <Input
                        id="sueldo_mensual"
                        v-model="formCalculo.sueldo_mensual"
                        type="number"
                        min="1"
                        step="0.01"
                        class="w-40"
                        placeholder="0.00"
                    />
                    <p
                        v-if="formCalculo.errors.sueldo_mensual"
                        class="text-xs text-destructive"
                    >
                        {{ formCalculo.errors.sueldo_mensual }}
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="sueldo_pendiente_inicial"
                        >Sueldo pendiente</Label
                    >
                    <Input
                        id="sueldo_pendiente_inicial"
                        v-model="formCalculo.sueldo_pendiente"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-40"
                        placeholder="0.00"
                    />
                    <p
                        v-if="formCalculo.errors.sueldo_pendiente"
                        class="text-xs text-destructive"
                    >
                        {{ formCalculo.errors.sueldo_pendiente }}
                    </p>
                </div>
                <Button
                    :disabled="formCalculo.processing || sueldoInvalido"
                    @click="calcularFiniquito"
                >
                    <Calculator class="size-4" />
                    Calcular finiquito
                </Button>
            </div>
        </div>

        <!-- Ya existe un cálculo: mostrar resumen, tabla de conceptos y acciones. -->
        <div v-else class="flex flex-col gap-4">
            <div class="grid gap-3 sm:grid-cols-3">
                <div>
                    <p class="text-xs text-muted-foreground">Antigüedad</p>
                    <p class="text-sm font-medium">
                        {{ finiquito.antiguedad_anios }} año(s),
                        {{ finiquito.antiguedad_meses }} mes(es)
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">
                        Sueldo mensual / diario
                    </p>
                    <p class="text-sm font-medium">
                        {{ moneda(finiquito.sueldo_mensual) }} /
                        {{ moneda(finiquito.sueldo_diario) }}
                    </p>
                </div>
                <div>
                    <p class="text-xs text-muted-foreground">
                        Vacaciones pendientes
                    </p>
                    <p class="text-sm font-medium">
                        {{ finiquito.vacaciones_pendientes }} días
                    </p>
                </div>
            </div>

            <!-- Desglose: automáticos (solo lectura) + capturados por RH
                 (editables/eliminables) — misma fuente que el PDF. -->
            <table class="w-full text-sm">
                <tbody class="divide-y divide-border/60">
                    <tr
                        v-if="ajustandoClave"
                        class="bg-warning-soft/40"
                    >
                        <td colspan="4" class="p-2">
                            <form class="flex flex-wrap items-end gap-2" @submit.prevent="guardarAjuste">
                                <span class="w-full text-xs font-medium">Ajuste autorizado de «{{ desglose.find((f) => f.concepto_clave === ajustandoClave)?.concepto }}» (calculado {{ moneda(desglose.find((f) => f.concepto_clave === ajustandoClave)?.valor_calculado ?? 0) }})</span>
                                <Input v-model="formAjuste.importe" type="number" min="0" step="0.01" class="w-32" title="Importe final" />
                                <Input v-model="formAjuste.motivo" class="min-w-[12rem] flex-1" placeholder="Motivo del ajuste (obligatorio)" />
                                <Button type="submit" size="sm" :disabled="formAjuste.processing">Guardar</Button>
                                <Button type="button" size="sm" variant="ghost" @click="ajustandoClave = null">Cancelar</Button>
                                <p v-if="formAjuste.errors.motivo || formAjuste.errors.importe" class="w-full text-xs text-destructive">{{ formAjuste.errors.motivo ?? formAjuste.errors.importe }}</p>
                            </form>
                        </td>
                    </tr>
                    <tr v-for="fila in desglose" :key="fila.id ?? fila.clave ?? fila.concepto">
                        <template v-if="fila.id !== editandoConceptoId">
                            <td class="w-12 py-1.5 font-mono text-xs text-muted-foreground tabular-nums">
                                {{ fila.clave }}
                            </td>
                            <td class="py-1.5 text-muted-foreground">
                                <span
                                    class="mr-1.5 inline-block rounded-full px-1.5 py-0.5 text-[10px] font-medium"
                                    :class="fila.tipo === 'deduccion' ? 'bg-destructive/10 text-destructive' : 'bg-primary/10 text-primary'"
                                    >{{ fila.tipo === 'deduccion' ? 'Deducción' : 'Percepción' }}</span
                                >
                                {{ fila.concepto }}
                                <span
                                    v-if="fila.dias"
                                    class="text-xs"
                                    >({{ fila.dias }} días)</span
                                >
                                <span
                                    v-if="fila.ajustado && fila.ajuste"
                                    class="mt-0.5 block text-[11px] text-warning"
                                    :title="fila.ajuste.motivo"
                                    >Ajustado: calculado {{ moneda(fila.ajuste.valor_calculado) }} → {{ moneda(fila.ajuste.valor_final) }} · {{ fila.ajuste.motivo }}<template v-if="fila.ajuste.usuario"> · {{ fila.ajuste.usuario }}</template></span
                                >
                                <span
                                    v-if="fila.origen === 'manual'"
                                    class="ml-1 rounded bg-muted px-1 py-0.5 text-[10px] uppercase text-muted-foreground"
                                    >manual</span
                                >
                            </td>
                            <td class="py-1.5 text-right tabular-nums">
                                {{ fila.tipo === 'deduccion' ? '−' : '' }}{{ moneda(fila.importe) }}
                            </td>
                            <td
                                v-if="fila.origen === 'manual' && puedeEditarAjustes"
                                class="w-16 py-1.5 text-right"
                            >
                                <button
                                    type="button"
                                    class="text-muted-foreground hover:text-foreground"
                                    @click="abrirEdicionConcepto(fila)"
                                >
                                    <Pencil class="size-3.5" />
                                </button>
                                <button
                                    type="button"
                                    class="ml-2 text-muted-foreground hover:text-destructive"
                                    @click="eliminarConcepto(fila.id!)"
                                >
                                    <Trash2 class="size-3.5" />
                                </button>
                            </td>
                            <td
                                v-else-if="fila.concepto_clave && puedeEditarAjustes && !cerrado"
                                class="w-16 py-1.5 text-right"
                            >
                                <button
                                    type="button"
                                    class="text-xs font-medium text-primary hover:underline"
                                    @click="abrirAjuste(fila)"
                                >
                                    Ajustar
                                </button>
                            </td>
                            <td v-else />
                        </template>

                        <!-- Edición en línea del concepto manual seleccionado. -->
                        <td v-else colspan="4" class="py-1.5">
                            <form
                                class="flex flex-wrap items-end gap-2"
                                @submit.prevent="guardarEdicionConcepto"
                            >
                                <SelectSimple
                                    v-model="formEditarConcepto.tipo"
                                    class="w-32"
                                    :opciones="TIPOS_CONCEPTO"
                                />
                                <Input
                                    v-model="formEditarConcepto.concepto"
                                    class="min-w-[9rem] flex-1"
                                    placeholder="Concepto"
                                />
                                <Input
                                    v-model="formEditarConcepto.cantidad"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-20"
                                    title="Cantidad"
                                />
                                <Input
                                    v-model="formEditarConcepto.importe"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    class="w-28"
                                    title="Importe"
                                />
                                <Button
                                    size="sm"
                                    type="submit"
                                    :disabled="formEditarConcepto.processing"
                                    >Guardar</Button
                                >
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    @click="editandoConceptoId = null"
                                    ><X class="size-4"
                                /></Button>
                            </form>
                        </td>
                    </tr>
                    <tr class="font-medium">
                        <td class="py-1.5">Total calculado</td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ moneda(finiquito.total_calculado) }}
                        </td>
                        <td />
                    </tr>
                </tbody>
            </table>

            <!-- Agregar un concepto manual (percepción o deducción). -->
            <div v-if="puedeEditarAjustes" class="flex flex-col gap-2">
                <Button
                    v-if="!agregandoConcepto"
                    type="button"
                    size="sm"
                    variant="ghost"
                    class="self-start"
                    @click="abrirNuevoConcepto"
                >
                    <Plus class="size-4" />
                    Agregar concepto
                </Button>
                <form
                    v-else
                    class="flex flex-wrap items-end gap-2 rounded-xl bg-muted/30 p-3"
                    @submit.prevent="guardarNuevoConcepto"
                >
                    <div class="grid gap-1.5">
                        <Label>Tipo</Label>
                        <SelectSimple
                            v-model="formNuevoConcepto.tipo"
                            class="w-32"
                            :opciones="TIPOS_CONCEPTO"
                        />
                    </div>
                    <div class="grid min-w-[10rem] flex-1 gap-1.5">
                        <Label>Concepto</Label>
                        <Input
                            v-model="formNuevoConcepto.concepto"
                            placeholder="Ej. Vales de despensa pendientes"
                        />
                        <p
                            v-if="formNuevoConcepto.errors.concepto"
                            class="text-xs text-destructive"
                        >
                            {{ formNuevoConcepto.errors.concepto }}
                        </p>
                    </div>
                    <div class="grid w-24 gap-1.5">
                        <Label>Cantidad</Label>
                        <Input
                            v-model="formNuevoConcepto.cantidad"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                    </div>
                    <div class="grid w-28 gap-1.5">
                        <Label>Importe</Label>
                        <Input
                            v-model="formNuevoConcepto.importe"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <p
                            v-if="formNuevoConcepto.errors.importe"
                            class="text-xs text-destructive"
                        >
                            {{ formNuevoConcepto.errors.importe }}
                        </p>
                    </div>
                    <div class="grid min-w-[10rem] flex-1 gap-1.5">
                        <Label>Observaciones (opcional)</Label>
                        <Input v-model="formNuevoConcepto.observaciones" />
                    </div>
                    <Button
                        type="submit"
                        size="sm"
                        :disabled="formNuevoConcepto.processing"
                        >Guardar concepto</Button
                    >
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="agregandoConcepto = false"
                        >Cancelar</Button
                    >
                </form>
            </div>

            <!-- Ajustes rápidos: bonos/descuentos/adeudos/comentarios. -->
            <dl
                v-if="cerrado"
                class="grid gap-3 rounded-xl bg-muted/30 p-3 text-sm sm:grid-cols-4"
            >
                <div>
                    <dt class="text-xs text-muted-foreground">Bonos extra</dt>
                    <dd>{{ moneda(finiquito.bonos_extra) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Descuentos</dt>
                    <dd>{{ moneda(finiquito.descuentos) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Adeudos</dt>
                    <dd>{{ moneda(finiquito.adeudos) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">
                        Total ajustado
                    </dt>
                    <dd class="font-semibold text-[var(--brand-primary)]">
                        {{ moneda(finiquito.total_ajustado) }}
                    </dd>
                </div>
                <div v-if="finiquito.comentarios_ajuste" class="sm:col-span-4">
                    <dt class="text-xs text-muted-foreground">Comentarios</dt>
                    <dd>{{ finiquito.comentarios_ajuste }}</dd>
                </div>
            </dl>
            <div
                v-else
                class="grid gap-3 rounded-xl bg-muted/30 p-3 sm:grid-cols-3"
            >
                <div class="grid gap-1.5">
                    <Label for="bonos_extra">Bonos extra</Label>
                    <Input
                        id="bonos_extra"
                        v-model="formAjustes.bonos_extra"
                        type="number"
                        min="0"
                        step="0.01"
                        :disabled="!puedeEditarAjustes"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="descuentos">Descuentos</Label>
                    <Input
                        id="descuentos"
                        v-model="formAjustes.descuentos"
                        type="number"
                        min="0"
                        step="0.01"
                        :disabled="!puedeEditarAjustes"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label for="adeudos">Adeudos</Label>
                    <Input
                        id="adeudos"
                        v-model="formAjustes.adeudos"
                        type="number"
                        min="0"
                        step="0.01"
                        :disabled="!puedeEditarAjustes"
                    />
                </div>
                <div class="grid gap-1.5 sm:col-span-3">
                    <Label for="comentarios_ajuste"
                        >Comentarios del ajuste</Label
                    >
                    <Textarea
                        id="comentarios_ajuste"
                        v-model="formAjustes.comentarios_ajuste"
                        rows="2"
                        :disabled="!puedeEditarAjustes"
                    />
                </div>
                <div
                    class="flex items-center justify-between gap-2 sm:col-span-3"
                >
                    <p class="text-sm font-semibold">
                        Total ajustado (estimado):
                        <span class="text-[var(--brand-primary)]">{{
                            moneda(totalAjustadoPreview)
                        }}</span>
                    </p>
                    <Button
                        v-if="puedeEditarAjustes"
                        size="sm"
                        variant="outline"
                        :disabled="formAjustes.processing"
                        @click="guardarAjustes"
                    >
                        Guardar ajustes
                    </Button>
                </div>
            </div>

            <p
                v-if="cerrado"
                class="rounded-lg bg-success/10 p-2.5 text-xs font-medium text-success"
            >
                Finiquito
                {{ finiquito.estado === 'pagado' ? 'pagado' : 'firmado' }} — no
                editable. Si hay un error, se requiere una corrección/anulación
                explícita (contacta a sistemas/RH).
            </p>
            <p
                v-else
                class="rounded-lg bg-warning/10 p-2.5 text-xs text-warning"
            >
                Cálculo editable y sujeto a validación de RH/contabilidad.
            </p>
            <p
                v-if="!permisos.usaFormatoOficial && !cerrado"
                class="rounded-lg bg-muted/50 p-2.5 text-xs text-muted-foreground"
            >
                No hay formato oficial de finiquito configurado; se generará un
                formato interno provisional.
            </p>

            <div
                v-if="permisos.puedeCalcular && !cerrado"
                class="flex flex-wrap items-end gap-2 rounded-xl bg-muted/30 p-3"
            >
                <div class="grid gap-1.5">
                    <Label for="sueldo_mensual_recalculo">Sueldo mensual</Label>
                    <Input
                        id="sueldo_mensual_recalculo"
                        v-model="formCalculo.sueldo_mensual"
                        type="number"
                        min="1"
                        step="0.01"
                        class="w-40"
                        placeholder="0.00"
                    />
                    <p
                        v-if="formCalculo.errors.sueldo_mensual"
                        class="text-xs text-destructive"
                    >
                        {{ formCalculo.errors.sueldo_mensual }}
                    </p>
                </div>
                <div class="grid gap-1.5">
                    <Label for="sueldo_pendiente_recalculo"
                        >Sueldo pendiente</Label
                    >
                    <Input
                        id="sueldo_pendiente_recalculo"
                        v-model="formCalculo.sueldo_pendiente"
                        type="number"
                        min="0"
                        step="0.01"
                        class="w-40"
                        placeholder="0.00"
                    />
                </div>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="formCalculo.processing || sueldoInvalido"
                    @click="recalcularFiniquito"
                >
                    <RefreshCw class="size-4" />
                    Recalcular
                </Button>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permisos.puedeRevisar"
                    size="sm"
                    :disabled="
                        formRevisar.processing ||
                        finiquito.estado !== 'borrador'
                    "
                    @click="revisarFiniquito"
                >
                    <FileCheck2 class="size-4" />
                    Marcar como revisado
                </Button>
                <Button
                    v-if="!cerrado"
                    size="sm"
                    variant="outline"
                    :disabled="formGenerarPdf.processing"
                    @click="generarPdfFiniquito"
                >
                    <FileCheck2 class="size-4" />
                    Generar PDF oficial
                </Button>
                <Button v-if="!cerrado" as-child size="sm" variant="outline">
                    <a :href="vistaPrevia.url(solicitudId)" target="_blank" rel="noopener">
                        <Eye class="size-4" />
                        Vista previa
                    </a>
                </Button>
                <Button
                    v-if="
                        finiquito.documento_generado_path ||
                        finiquito.documento_firmado_path
                    "
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <a
                        :href="descargarPdf.url(solicitudId)"
                        target="_blank"
                        rel="noopener"
                    >
                        <Download class="size-4" />
                        {{
                            finiquito.documento_firmado_path
                                ? 'Ver finiquito firmado'
                                : 'Descargar PDF'
                        }}
                    </a>
                </Button>
                <label
                    v-if="permisos.puedeSubirFirmado && !cerrado"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-dashed px-3 py-1.5 text-sm text-muted-foreground hover:bg-accent"
                >
                    <Upload class="size-4" />
                    Subir firmado
                    <input
                        ref="inputFirmado"
                        type="file"
                        class="hidden"
                        accept=".pdf,.jpg,.jpeg,.png"
                        :disabled="formFirmado.processing"
                        @change="subirFirmado"
                    />
                </label>
            </div>

            <p
                v-if="finiquito.documento_firmado_path"
                class="text-xs text-muted-foreground"
            >
                Documento firmado guardado en el expediente de la solicitud.
            </p>
        </div>
    </div>
</template>
