<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import {
    Calculator,
    Download,
    Eye,
    FileCheck2,
    Plus,
    RefreshCw,
    Trash2,
    Upload,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EstadoBadge from '@/components/Common/EstadoBadge.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
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

type OtroConcepto = { nombre: string; monto: string; tipo: 'suma' | 'resta' };

function otrosConceptosDesdeFiniquito(): OtroConcepto[] {
    const registro = props.finiquito?.otros_conceptos ?? null;

    if (!registro) {
        return [];
    }

    return Object.entries(registro).map(([nombre, valor]) => ({
        nombre,
        monto: String(Math.abs(Number(valor))),
        tipo: Number(valor) < 0 ? 'resta' : 'suma',
    }));
}

const otrosConceptos = ref<OtroConcepto[]>(otrosConceptosDesdeFiniquito());

function agregarConcepto() {
    otrosConceptos.value.push({ nombre: '', monto: '0', tipo: 'suma' });
}

function quitarConcepto(index: number) {
    otrosConceptos.value.splice(index, 1);
}

function otrosConceptosComoRegistro(): Record<string, number> {
    const registro: Record<string, number> = {};

    for (const concepto of otrosConceptos.value) {
        const nombre = concepto.nombre.trim();
        const monto = Number(concepto.monto) || 0;

        if (nombre === '' || monto === 0) {
            continue;
        }

        registro[nombre] = concepto.tipo === 'resta' ? -monto : monto;
    }

    return registro;
}

const formAjustes = useForm({
    bonos_extra: props.finiquito?.bonos_extra ?? '0',
    descuentos: props.finiquito?.descuentos ?? '0',
    adeudos: props.finiquito?.adeudos ?? '0',
    otros_conceptos: {} as Record<string, number>,
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
    const otros = Object.values(otrosConceptosComoRegistro()).reduce(
        (suma, valor) => suma + valor,
        0,
    );

    return base + bonos - descuentos - adeudos + otros;
});

function guardarAjustes() {
    formAjustes.otros_conceptos = otrosConceptosComoRegistro();
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
                <div class="grid gap-1.5">
                    <Label for="sueldo_pendiente_inicial">Sueldo pendiente</Label>
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
                <div class="grid gap-2 sm:col-span-3">
                    <div class="flex items-center justify-between">
                        <Label>Otros conceptos</Label>
                        <Button
                            v-if="puedeEditarAjustes"
                            type="button"
                            size="sm"
                            variant="ghost"
                            @click="agregarConcepto"
                        >
                            <Plus class="size-4" />
                            Agregar concepto
                        </Button>
                    </div>
                    <p
                        v-if="otrosConceptos.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        Sin conceptos adicionales.
                    </p>
                    <div
                        v-for="(concepto, index) in otrosConceptos"
                        :key="index"
                        class="flex flex-wrap items-end gap-2"
                    >
                        <div class="grid min-w-[10rem] flex-1 gap-1.5">
                            <Label :for="`concepto_nombre_${index}`"
                                >Concepto</Label
                            >
                            <Input
                                :id="`concepto_nombre_${index}`"
                                v-model="concepto.nombre"
                                placeholder="Ej. Vales de despensa"
                                :disabled="!puedeEditarAjustes"
                            />
                        </div>
                        <div class="grid w-32 gap-1.5">
                            <Label :for="`concepto_monto_${index}`"
                                >Monto</Label
                            >
                            <Input
                                :id="`concepto_monto_${index}`"
                                v-model="concepto.monto"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="!puedeEditarAjustes"
                            />
                        </div>
                        <div class="grid w-28 gap-1.5">
                            <Label :for="`concepto_tipo_${index}`"
                                >Tipo</Label
                            >
                            <NativeSelect
                                :id="`concepto_tipo_${index}`"
                                v-model="concepto.tipo"
                                :disabled="!puedeEditarAjustes"
                            >
                                <option value="suma">Suma</option>
                                <option value="resta">Resta</option>
                            </NativeSelect>
                        </div>
                        <Button
                            v-if="puedeEditarAjustes"
                            type="button"
                            size="icon"
                            variant="ghost"
                            @click="quitarConcepto(index)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
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
                v-if="finiquito.estado === 'firmado'"
                class="rounded-lg bg-emerald-500/10 p-2.5 text-xs font-medium text-emerald-700 dark:text-emerald-400"
            >
                Finiquito firmado — no editable. Si hay un error, se requiere
                una corrección/anulación explícita (contacta a sistemas/RH).
            </p>
            <p
                v-else
                class="rounded-lg bg-amber-500/10 p-2.5 text-xs text-amber-700 dark:text-amber-400"
            >
                Cálculo editable y sujeto a validación de RH/contabilidad.
            </p>
            <p
                v-if="!permisos.usaFormatoOficial"
                class="rounded-lg bg-muted/50 p-2.5 text-xs text-muted-foreground"
            >
                No hay formato oficial de finiquito configurado; se generará
                un formato interno provisional.
            </p>

            <div
                v-if="permisos.puedeCalcular && finiquito.estado !== 'firmado'"
                class="flex flex-wrap items-end gap-2 rounded-xl bg-muted/30 p-3"
            >
                <div class="grid gap-1.5">
                    <Label for="sueldo_mensual_recalculo"
                        >Sueldo mensual</Label
                    >
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
                    :disabled="formRevisar.processing || finiquito.estado !== 'borrador'"
                    @click="revisarFiniquito"
                >
                    <FileCheck2 class="size-4" />
                    Marcar como revisado
                </Button>
                <Button
                    v-if="finiquito.estado !== 'firmado'"
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
