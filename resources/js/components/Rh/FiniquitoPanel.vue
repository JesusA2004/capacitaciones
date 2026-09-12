<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Calculator,
    Download,
    Eye,
    FileCheck2,
    RefreshCw,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
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
} from '@/routes/rh/solicitudes/finiquito';
import type { FiniquitoCalculoItem, FiniquitoPermisos } from '@/types';

const props = defineProps<{
    solicitudId: number;
    finiquito: FiniquitoCalculoItem | null;
    permisos: FiniquitoPermisos;
}>();

function moneda(valor: string | number): string {
    return `$${Number(valor).toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

const formCalculo = useForm({ sueldo_mensual: '' });

function calcularFiniquito() {
    formCalculo.post(calcular.url(props.solicitudId), { preserveScroll: true });
}

function recalcularFiniquito() {
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

const puedeEditarAjustes = computed(
    () => props.finiquito !== null && props.finiquito.estado !== 'firmado',
);
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
                finiquito. El cálculo es automático pero queda abierto a
                ajustes antes de revisarse.
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
                <Button
                    :disabled="formCalculo.processing"
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

            <table class="w-full text-sm">
                <tbody class="divide-y divide-border/60">
                    <tr>
                        <td class="py-1.5 text-muted-foreground">
                            Sueldo pendiente
                        </td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ moneda(finiquito.sueldo_pendiente) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-muted-foreground">
                            Prima vacacional
                        </td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ moneda(finiquito.prima_vacacional) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-muted-foreground">
                            Aguinaldo proporcional
                            ({{ finiquito.dias_trabajados_periodo }} días)
                        </td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ moneda(finiquito.aguinaldo_proporcional) }}
                        </td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-muted-foreground">
                            Indemnización
                        </td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ moneda(finiquito.indemnizacion) }}
                        </td>
                    </tr>
                    <tr class="font-medium">
                        <td class="py-1.5">Total calculado</td>
                        <td class="py-1.5 text-right tabular-nums">
                            {{ moneda(finiquito.total_calculado) }}
                        </td>
                    </tr>
                </tbody>
            </table>

            <!-- Ajustes manuales: bonos/descuentos/adeudos/comentarios. -->
            <div class="grid gap-3 rounded-xl bg-muted/30 p-3 sm:grid-cols-3">
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
                        Total ajustado:
                        <span class="text-[var(--brand-primary)]">{{
                            moneda(finiquito.total_ajustado)
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

            <p class="rounded-lg bg-amber-500/10 p-2.5 text-xs text-amber-700 dark:text-amber-400">
                Cálculo editable y sujeto a validación de RH/contabilidad.
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="permisos.puedeCalcular"
                    size="sm"
                    variant="outline"
                    :disabled="formCalculo.processing || finiquito.estado === 'firmado'"
                    @click="recalcularFiniquito"
                >
                    <RefreshCw class="size-4" />
                    Recalcular
                </Button>
                <Button
                    v-if="permisos.puedeRevisar"
                    size="sm"
                    :disabled="formRevisar.processing || finiquito.estado !== 'borrador'"
                    @click="revisarFiniquito"
                >
                    <FileCheck2 class="size-4" />
                    Marcar como revisado
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="formGenerarPdf.processing"
                    @click="generarPdfFiniquito"
                >
                    <Eye class="size-4" />
                    Generar / vista previa PDF
                </Button>
                <Button
                    v-if="finiquito.documento_generado_path"
                    as-child
                    size="sm"
                    variant="outline"
                >
                    <a :href="descargarPdf.url(solicitudId)" target="_blank" rel="noopener">
                        <Download class="size-4" />
                        Descargar PDF
                    </a>
                </Button>
                <label
                    v-if="permisos.puedeSubirFirmado && finiquito.estado !== 'firmado'"
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
