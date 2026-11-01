<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { FileSearch, Plus, Receipt, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import DatePicker from '@/components/Common/DatePicker.vue';
import InputError from '@/components/InputError.vue';
import DocumentPreviewDialog from '@/components/people/DocumentPreviewDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useAlertas } from '@/composables/useAlertas';
import { formatearFecha } from '@/lib/fechas';
import { vistaPreviaPost } from '@/lib/http';
import {
    descargar as descargarRecibo,
    regenerarPdf as regenerarPdfReciboRoute,
    store as generarReciboNomina,
    vistaPrevia as vistaPreviaReciboNomina,
} from '@/routes/rh/expedientes/recibos-nomina';
import type {
    ExpedienteColaborador,
    PrestamoItem,
    ReciboNominaItem,
} from '@/types';

const props = defineProps<{
    colaborador: ExpedienteColaborador;
    /** Solo RH (nomina.recibos.crear) genera recibos, nunca el propio. */
    puedeGenerarRecibos?: boolean;
    puedeVerSueldo: boolean;
    /** null = quien mira no puede ver recibos (el colaborador nunca ve los suyos: se entregan impresos). */
    recibosNomina: ReciboNominaItem[] | null;
    prestamos: PrestamoItem[];
}>();

const { mostrarExito, mostrarError } = useAlertas();

function sueldoFormateado(valor: string | null): string {
    if (!valor) {
        return 'Sin capturar';
    }

    return Number(valor).toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}

function moneda(valor: number): string {
    return valor.toLocaleString('es-MX', {
        style: 'currency',
        currency: 'MXN',
        minimumFractionDigits: 2,
    });
}

const prestamoActivo = computed(
    () =>
        props.prestamos.find((prestamo) => prestamo.estado === 'activo') ??
        null,
);

const dialogoReciboAbierto = ref(false);

type ConceptoForm = {
    concepto: string;
    monto: number;
    tipo?: string;
    prestamo_id?: number;
};

const formRecibo = useForm({
    periodo_inicio: '',
    periodo_fin: '',
    fecha_pago: '',
    sueldo_base: 0,
    percepciones: [] as ConceptoForm[],
    deducciones: [] as ConceptoForm[],
});

function sueldoSugeridoPeriodo(): number {
    const mensual = Number(props.colaborador.sueldo_mensual ?? 0);

    if (!formRecibo.periodo_inicio || !formRecibo.periodo_fin) {
        return mensual;
    }

    const inicio = new Date(formRecibo.periodo_inicio);
    const fin = new Date(formRecibo.periodo_fin);
    const dias = Math.round((fin.getTime() - inicio.getTime()) / 86400000) + 1;

    if (dias <= 0) {
        return mensual;
    }

    return Math.round(((mensual / 30) * dias + Number.EPSILON) * 100) / 100;
}

watch([() => formRecibo.periodo_inicio, () => formRecibo.periodo_fin], () => {
    formRecibo.sueldo_base = sueldoSugeridoPeriodo();
});

function abrirDialogoRecibo() {
    formRecibo.reset();
    formRecibo.clearErrors();
    formRecibo.percepciones = [];
    formRecibo.sueldo_base = Number(props.colaborador.sueldo_mensual ?? 0);

    // Sugerencia automática (RH puede quitarla/ajustarla antes de
    // confirmar): línea de pago del préstamo activo con el pago
    // programado — ver App\Services\Nomina\ReciboNominaService::generar().
    formRecibo.deducciones = prestamoActivo.value
        ? [
              {
                  concepto: 'Pago de préstamo interno',
                  monto: Number(prestamoActivo.value.pago_programado),
                  tipo: 'prestamo',
                  prestamo_id: prestamoActivo.value.id,
              },
          ]
        : [];

    dialogoReciboAbierto.value = true;
}

function agregarPercepcion() {
    formRecibo.percepciones.push({ concepto: '', monto: 0 });
}

function agregarDeduccion() {
    formRecibo.deducciones.push({ concepto: '', monto: 0 });
}

function generarRecibo() {
    formRecibo.post(generarReciboNomina.url(props.colaborador.id), {
        preserveScroll: true,
        onSuccess: () => {
            mostrarExito('Recibo de nómina generado correctamente.');
            dialogoReciboAbierto.value = false;
        },
        onError: () =>
            mostrarError('No fue posible generar el recibo de nómina.'),
    });
}

const vistaPreviaReciboUrl = ref<string | null>(null);

const vistaPreviaReciboAbierta = ref(false);

const cargandoVistaPreviaRecibo = ref(false);

function limpiarVistaPreviaRecibo() {
    if (vistaPreviaReciboUrl.value) {
        URL.revokeObjectURL(vistaPreviaReciboUrl.value);
        vistaPreviaReciboUrl.value = null;
    }
}

async function verVistaPreviaRecibo() {
    cargandoVistaPreviaRecibo.value = true;

    try {
        const url = await vistaPreviaPost(
            vistaPreviaReciboNomina.url(props.colaborador.id),
            formRecibo.data(),
        );
        limpiarVistaPreviaRecibo();
        vistaPreviaReciboUrl.value = url;
        vistaPreviaReciboAbierta.value = true;
    } catch (e) {
        mostrarError(
            e instanceof Error
                ? e.message
                : 'No se pudo generar la vista previa del recibo.',
        );
    } finally {
        cargandoVistaPreviaRecibo.value = false;
    }
}

watch(vistaPreviaReciboAbierta, (abierta) => {
    if (!abierta) {
        limpiarVistaPreviaRecibo();
    }
});

const formRegenerarPdf = useForm({});

const reciboRegenerandoId = ref<number | null>(null);

function regenerarPdfRecibo(reciboId: number) {
    reciboRegenerandoId.value = reciboId;

    formRegenerarPdf.post(regenerarPdfReciboRoute.url(reciboId), {
        preserveScroll: true,
        onSuccess: () => mostrarExito('PDF regenerado correctamente.'),
        onError: () => mostrarError('No fue posible regenerar el PDF.'),
        onFinish: () => (reciboRegenerandoId.value = null),
    });
}

function descargarReciboUrl(reciboId: number): string {
    return descargarRecibo.url(reciboId);
}
</script>

<template>
    <Card class="rounded-2xl border-border/60">
        <CardHeader
            class="flex flex-row flex-wrap items-center justify-between gap-2"
        >
            <CardTitle class="flex items-center gap-2 text-base">
                <Receipt class="size-4" />
                Recibos de nómina
            </CardTitle>
            <Button
                v-if="puedeGenerarRecibos && colaborador.sueldo_mensual"
                size="sm"
                type="button"
                @click="abrirDialogoRecibo"
            >
                <Plus class="size-4" />
                Generar recibo
            </Button>
        </CardHeader>
        <CardContent class="flex flex-col gap-2">
            <p
                v-if="puedeVerSueldo && !colaborador.sueldo_mensual"
                class="text-sm text-muted-foreground"
            >
                Captura el sueldo mensual en «Datos laborales» antes de generar
                un recibo.
            </p>
            <div
                v-for="recibo in recibosNomina ?? []"
                :key="recibo.id"
                class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-border/60 p-3 text-sm"
            >
                <div class="min-w-0">
                    <p class="font-medium">
                        {{ recibo.periodo_inicio }} —
                        {{ recibo.periodo_fin }}
                    </p>
                    <p class="text-xs text-muted-foreground">
                        Pago:
                        {{ formatearFecha(recibo.fecha_pago) }}
                        · Neto:
                        {{ moneda(recibo.neto) }}
                    </p>
                </div>
                <a
                    v-if="recibo.tiene_pdf"
                    :href="descargarReciboUrl(recibo.id)"
                    target="_blank"
                    class="inline-flex items-center gap-1.5 text-sm font-medium text-primary hover:underline"
                >
                    <Receipt class="size-3.5" />
                    Descargar
                </a>
                <div
                    v-else
                    class="flex items-center gap-2 text-xs text-muted-foreground"
                >
                    <span>Recibo guardado, PDF no disponible.</span>
                    <Button
                        v-if="puedeGenerarRecibos"
                        size="sm"
                        variant="outline"
                        type="button"
                        :disabled="
                            formRegenerarPdf.processing &&
                            reciboRegenerandoId === recibo.id
                        "
                        @click="regenerarPdfRecibo(recibo.id)"
                    >
                        Regenerar PDF
                    </Button>
                </div>
            </div>
            <p
                v-if="!recibosNomina?.length"
                class="text-sm text-muted-foreground"
            >
                Sin recibos generados.
            </p>
        </CardContent>
    </Card>
    <Dialog v-model:open="dialogoReciboAbierto">
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Generar recibo de nómina</DialogTitle>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="generarRecibo">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div class="grid gap-1.5">
                        <Label>Inicio del periodo</Label>
                        <DatePicker v-model="formRecibo.periodo_inicio" />
                        <InputError
                            :message="formRecibo.errors.periodo_inicio"
                        />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Fin del periodo</Label>
                        <DatePicker v-model="formRecibo.periodo_fin" />
                        <InputError :message="formRecibo.errors.periodo_fin" />
                    </div>
                    <div class="grid gap-1.5">
                        <Label>Fecha de pago</Label>
                        <DatePicker v-model="formRecibo.fecha_pago" />
                        <InputError :message="formRecibo.errors.fecha_pago" />
                    </div>
                </div>

                <div>
                    <Label class="mb-2 block">Percepciones</Label>
                    <div
                        class="flex items-center justify-between rounded-lg border border-border/60 bg-muted/40 p-2.5 text-sm"
                    >
                        <span class="text-muted-foreground"
                            >Sueldo mensual registrado</span
                        >
                        <span class="font-medium">{{
                            sueldoFormateado(colaborador.sueldo_mensual)
                        }}</span>
                    </div>
                    <div class="mt-2 grid gap-1.5">
                        <Label for="sueldo-base-periodo" class="text-xs">
                            Sueldo base de este recibo
                        </Label>
                        <Input
                            id="sueldo-base-periodo"
                            v-model.number="formRecibo.sueldo_base"
                            type="number"
                            step="0.01"
                            min="0"
                        />
                        <p class="text-xs text-muted-foreground">
                            Sugerido según el periodo seleccionado; RH puede
                            editarlo antes de generar el recibo.
                        </p>
                        <InputError :message="formRecibo.errors.sueldo_base" />
                    </div>
                    <div
                        v-for="(percepcion, indice) in formRecibo.percepciones"
                        :key="indice"
                        class="mt-2 flex items-center gap-2"
                    >
                        <Input
                            v-model="percepcion.concepto"
                            placeholder="Concepto (bono, comisión, otros)"
                            class="flex-1"
                        />
                        <Input
                            v-model.number="percepcion.monto"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-32"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="formRecibo.percepciones.splice(indice, 1)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        @click="agregarPercepcion"
                    >
                        <Plus class="size-4" />
                        Agregar percepción
                    </Button>
                </div>

                <div>
                    <Label class="mb-2 block">Deducciones</Label>
                    <p
                        v-if="!formRecibo.deducciones.length"
                        class="text-xs text-muted-foreground"
                    >
                        Sin deducciones agregadas.
                    </p>
                    <div
                        v-for="(deduccion, indice) in formRecibo.deducciones"
                        :key="indice"
                        class="mt-2 flex items-center gap-2"
                    >
                        <Input
                            v-model="deduccion.concepto"
                            placeholder="Concepto"
                            class="flex-1"
                        />
                        <Input
                            v-model.number="deduccion.monto"
                            type="number"
                            step="0.01"
                            min="0"
                            class="w-32"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            @click="formRecibo.deducciones.splice(indice, 1)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        class="mt-2"
                        @click="agregarDeduccion"
                    >
                        <Plus class="size-4" />
                        Agregar deducción
                    </Button>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialogoReciboAbierto = false"
                    >
                        Cancelar
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="
                            cargandoVistaPreviaRecibo || formRecibo.processing
                        "
                        @click="verVistaPreviaRecibo"
                    >
                        <Spinner v-if="cargandoVistaPreviaRecibo" />
                        <FileSearch v-else class="size-4" />
                        Vista previa
                    </Button>
                    <Button type="submit" :disabled="formRecibo.processing">
                        Generar recibo
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
    <DocumentPreviewDialog
        :open="vistaPreviaReciboAbierta"
        :preview-url="vistaPreviaReciboUrl"
        nombre="Recibo de nómina (vista previa).pdf"
        mime="application/pdf"
        estado="Vista previa — todavía no se genera"
        @update:open="(v) => (vistaPreviaReciboAbierta = v)"
    />
</template>
